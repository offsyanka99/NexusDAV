<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Dav\Push\PushPrecondition;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Push registrations: register, renew, unregister, and the cleanup paths
 * (docs/design/webdav-push.md).
 *
 * A row is one push resource on one DAV path. Registering the same push
 * resource on that path again updates the row and keeps its registration
 * URL. A deleted collection's rows are retired, not deleted: they get the
 * deletion's message and then go (PushChanges). Every other cleanup path
 * deletes at once.
 */
final class PushSubscriptions
{
    public const DEFAULT_DAYS = 3;

    public const MAX_DAYS = 7;

    public const MIN_SECONDS = 3600;

    /**
     * A retired row whose message was never sent (push switched off, no
     * worker) is pruned after this.
     */
    public const RETIRED_MINUTES = 60;

    /**
     * Registers or renews. Returns the row and whether it is new.
     *
     * @return array{0: PushSubscription, 1: bool}
     */
    public function register(User $user, ?int $appPasswordId, PushCapability $capability, PushRegistration $registration): array
    {
        $content = $registration->contentUpdate
            ? self::lower($registration->contentDepth ?? $capability->contentDepth, $capability->contentDepth)
            : null;
        $property = $registration->propertyUpdate && $capability->propertyDepth !== null
            ? self::lower($registration->propertyDepth ?? $capability->propertyDepth, $capability->propertyDepth)
            : null;

        if ($content === null && $property === null) {
            throw new PushPrecondition(PushPrecondition::NO_TRIGGER, 'None of the requested triggers is supported on this resource.');
        }

        $values = [
            'user_id' => $user->getKey(),
            'app_password_id' => $appPasswordId,
            'path' => $capability->path,
            'topic' => $capability->topic,
            'push_resource' => $registration->pushResource,
            'public_key' => $registration->publicKey,
            'auth_secret' => $registration->authSecret,
            'content_update_depth' => $content,
            'property_update_depth' => $property,
            'properties' => $property === null ? null : $registration->properties,
            'expires_at' => $this->expiry($registration->expires),
            'removed_at' => null,
        ];
        $scope = hash('sha256', $capability->path."\n".$registration->pushResource);

        // Two first registrations at once: the second insert hits the unique
        // scope and is retried as an update.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $scope, $values): array {
                    $existing = PushSubscription::query()->where('scope', $scope)->lockForUpdate()->first();

                    if ($existing instanceof PushSubscription) {
                        $existing->fill($values)->save();

                        return [$existing, false];
                    }

                    $this->pruneUser((int) $user->getKey());

                    if ($this->activeCount((int) $user->getKey()) >= PushConfig::maxSubscriptions()) {
                        throw new PushPrecondition(PushPrecondition::NOT_AVAILABLE, 'Too many push subscriptions for this user.');
                    }

                    $subscription = new PushSubscription($values + [
                        'registration' => self::newRegistration(),
                        'scope' => $scope,
                    ]);
                    $subscription->save();

                    return [$subscription, true];
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 2) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Removes the user's registration. False for an unknown, expired or
     * another user's id: those answer 404 alike.
     */
    public function unregister(User $user, string $registration): bool
    {
        $subscription = PushSubscription::query()
            ->where('registration', $registration)
            ->where('user_id', $user->getKey())
            ->first();

        if (! $subscription instanceof PushSubscription) {
            return false;
        }

        $subscription->delete();

        return $subscription->removed_at === null && $subscription->expires_at->isFuture();
    }

    /**
     * Absolute, from APP_URL, and compared as a string with the values of
     * Push-Dont-Notify, so it must always be built the same way.
     */
    public function registrationUrl(string $registration): string
    {
        return $this->registrationBase().$registration;
    }

    /**
     * The registrations among these URLs that belong to the user. Anything
     * else is ignored, as the draft requires.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    public function registrationsOf(int $userId, array $urls): array
    {
        $base = $this->registrationBase();
        $ids = [];

        foreach ($urls as $url) {
            $id = str_starts_with($url, $base) ? substr($url, strlen($base)) : '';

            if (preg_match('/^[A-Za-z0-9_-]{32}$/', $id) === 1) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return PushSubscription::query()
            ->where('user_id', $userId)
            ->whereIn('registration', array_keys($ids))
            ->pluck('registration')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * The granted expiry: the requested time, but at most seven days and at
     * least one hour from now; three days when none was asked for.
     */
    public function expiry(?DateTimeInterface $requested): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();

        if ($requested === null) {
            return $now->addDays(self::DEFAULT_DAYS);
        }

        $requested = CarbonImmutable::instance($requested);

        return $requested->min($now->addDays(self::MAX_DAYS))->max($now->addSeconds(self::MIN_SECONDS));
    }

    public function activeCount(int $userId): int
    {
        return PushSubscription::query()->active()->where('user_id', $userId)->count();
    }

    /**
     * A share went, or a sharee left it: the rows on that path and below go
     * at once. Retired rows wait for their message.
     */
    public function forgetPath(string $path): void
    {
        $this->underPath($path)->whereNull('removed_at')->delete();
    }

    public function forgetTopic(string $topic): void
    {
        PushSubscription::query()->where('topic', $topic)->delete();
    }

    public function forgetUser(int $userId): void
    {
        PushSubscription::query()->where('user_id', $userId)->delete();
    }

    public function forgetAppPassword(int $appPasswordId): void
    {
        PushSubscription::query()->where('app_password_id', $appPasswordId)->delete();
    }

    /**
     * The collection behind this topic was deleted.
     */
    public function retireTopic(string $topic): void
    {
        PushSubscription::query()->where('topic', $topic)->whereNull('removed_at')->update(['removed_at' => now()]);
    }

    /**
     * A file folder was deleted or moved away: its rows and those of the
     * folders inside it are retired. Returns their topics.
     *
     * @return list<string>
     */
    public function retirePath(string $path): array
    {
        $topics = $this->underPath($path)->whereNull('removed_at')->distinct()->pluck('topic')
            ->map(static fn (mixed $topic): string => (string) $topic)
            ->values()
            ->all();

        if ($topics !== []) {
            $this->underPath($path)->whereNull('removed_at')->update(['removed_at' => now()]);
        }

        return $topics;
    }

    /**
     * Expired rows, and retired rows whose message never went.
     */
    public function prune(): void
    {
        PushSubscription::query()
            ->where(function (Builder $query): void {
                $query->where('expires_at', '<=', now())
                    ->orWhere('removed_at', '<=', now()->subMinutes(self::RETIRED_MINUTES));
            })
            ->delete();
    }

    private function pruneUser(int $userId): void
    {
        PushSubscription::query()->where('user_id', $userId)->where('expires_at', '<=', now())->delete();
    }

    /**
     * @return Builder<PushSubscription>
     */
    private function underPath(string $path): Builder
    {
        $path = trim($path, '/');
        $child = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path).'/%';

        return PushSubscription::query()->where(function (Builder $query) use ($path, $child): void {
            $query->where('path', $path)->orWhereRaw('path LIKE ? ESCAPE ?', [$child, '\\']);
        });
    }

    private function registrationBase(): string
    {
        return rtrim((string) config('app.url'), '/').'/dav/push/';
    }

    /**
     * The shallower of the requested and the supported depth (the draft's
     * fallback for a depth the resource does not support).
     */
    private static function lower(string $requested, string $supported): string
    {
        return PushTopics::covers($supported, $requested) ? $requested : $supported;
    }

    /**
     * 32 URL-safe characters.
     */
    private static function newRegistration(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}

<?php

namespace App\Services\Dav;

use App\Models\AppPassword;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\AuthThrottle;
use App\Services\DiagnosticEventWriter;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Basic auth for an app-password token. The account password is never checked.
 * Failure reasons stay on this object. The client only receives a generic 401.
 */
final class DavAuthBackend extends AbstractBasic
{
    public const UNKNOWN_USER = 'unknown user';

    public const BAD_SECRET = 'bad secret';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public const USER_DISABLED = 'user disabled';

    public const MAIN_PASSWORD = 'main password used';

    private ?string $failureReason = null;

    private ?string $publicId = null;

    private ?string $username = null;

    private ?string $attemptedUsername = null;

    public function __construct(
        private AppPasswordService $passwords,
        private DiagnosticEventWriter $events,
        private AuthThrottle $throttle,
    ) {
        $this->realm = 'NexusDAV';
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * Public id of a token-shaped password, when one was sent. Never the secret.
     */
    public function publicId(): ?string
    {
        return $this->publicId;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    /**
     * @param  string  $username
     * @param  string  $password
     */
    protected function validateUserPass($username, $password)
    {
        $login = (string) $username;

        // Over either limit: do not look the token up or store another row.
        if ($this->throttle->tooManyFromAddress() || $this->throttle->tooMany(DiagnosticEvent::SOURCE_DAV, $login)) {
            return false;
        }

        $canonical = $this->attempt($login, (string) $password);

        if ($canonical !== null) {
            return true;
        }

        if (! is_string($this->failureReason) || $this->failureReason === '') {
            return false;
        }

        $this->events->recordAuthFailure(
            DiagnosticEvent::SOURCE_DAV,
            $this->throttle->clientIp(),
            (string) $this->attemptedUsername,
            $this->publicId,
            request()->getMethod(),
            request()->getPathInfo(),
            401,
            $this->failureReason,
        );
        $this->throttle->hit(DiagnosticEvent::SOURCE_DAV, $login);
        $this->throttle->hitAddress();

        $known = $this->attemptedUsername;

        if (is_string($known) && $this->throttle->key(DiagnosticEvent::SOURCE_DAV, $known) !== $this->throttle->key(DiagnosticEvent::SOURCE_DAV, $login)) {
            $this->throttle->hit(DiagnosticEvent::SOURCE_DAV, $known);
        }

        return false;
    }

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        $this->failureReason = null;
        $this->publicId = null;
        $this->username = null;
        $this->attemptedUsername = null;

        $result = parent::check($request, $response);

        // validateUserPass() stores the canonical username during parent::check().
        /** @var string|null $username */
        $username = $this->username;

        if ($result[0] !== true || ! is_string($username) || $username === '') {
            return [false, 'Unauthorized'];
        }

        return [true, 'principals/'.$username];
    }

    /**
     * Canonical username on success, null on failure.
     * is_active is read from the database on every call.
     */
    private function attempt(string $login, string $password): ?string
    {
        $this->publicId = $this->passwords->tokenPublicId($password);
        $this->attemptedUsername = mb_strtolower(trim($login));

        $user = User::query()->where('username', strtolower($login))->first();

        if (! $user instanceof User) {
            $user = User::query()->whereRaw('lower(email) = lower(?)', [$login])->first();
        }

        if (! $user instanceof User) {
            // Same token lookup as for a known user, so the response time
            // does not show whether the username exists.
            $this->passwords->verify($password);
            $this->failureReason = self::UNKNOWN_USER;

            return null;
        }

        $this->attemptedUsername = $user->username;

        // The email and the username share this bucket once the account is known.
        if ($this->throttle->tooMany(DiagnosticEvent::SOURCE_DAV, $user->username)) {
            return null;
        }

        $failure = null;
        $appPassword = $this->passwords->verify($password, $failure);

        if (! $appPassword instanceof AppPassword) {
            $this->failureReason = match ($failure) {
                AppPasswordService::FAILURE_SHAPE => self::MAIN_PASSWORD,
                AppPasswordService::FAILURE_REVOKED => self::REVOKED,
                AppPasswordService::FAILURE_EXPIRED => self::EXPIRED,
                default => self::BAD_SECRET,
            };

            return null;
        }

        if ((int) $appPassword->user_id !== (int) $user->getKey()) {
            $this->failureReason = self::BAD_SECRET;

            return null;
        }

        if (! $user->is_active) {
            $this->failureReason = self::USER_DISABLED;

            return null;
        }

        $this->passwords->markUsed($appPassword);
        $this->failureReason = null;
        $this->username = $user->username;

        return $user->username;
    }
}

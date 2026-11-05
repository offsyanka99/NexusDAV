<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\LocaleCatalog;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Settings changed on the Settings and Configuration pages.
 *
 * Each setting maps to one config key and one environment variable. The
 * config files read that variable, so it wins whenever it is set, and the
 * panel shows the field as locked. Otherwise a saved value replaces the
 * config default in apply(), which runs at every boot after config (cached
 * or not) is loaded. Saved values are cached outside the database, so a DAV
 * request does not query them; saving clears that cache.
 */
final class SettingsService
{
    public const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * @var array<string, array{config: string, env: string, type: string, default?: mixed, min?: int, max?: int, options?: list<string>}>
     */
    public const DEFINITIONS = [
        'caldav' => ['config' => 'nexusdav.features.caldav', 'env' => 'NEXUSDAV_CALDAV', 'type' => 'bool', 'default' => true],
        'carddav' => ['config' => 'nexusdav.features.carddav', 'env' => 'NEXUSDAV_CARDDAV', 'type' => 'bool', 'default' => true],
        'tasks' => ['config' => 'nexusdav.features.tasks', 'env' => 'NEXUSDAV_TASKS', 'type' => 'bool', 'default' => true],
        'notes' => ['config' => 'nexusdav.features.notes', 'env' => 'NEXUSDAV_NOTES', 'type' => 'bool', 'default' => true],
        'default_locale' => ['config' => 'nexusdav.default_locale', 'env' => 'NEXUSDAV_LOCALE', 'type' => 'locale', 'default' => 'en'],
        'log_level' => ['config' => 'logging.channels.stderr.level', 'env' => 'LOG_LEVEL', 'type' => 'choice', 'options' => self::LOG_LEVELS, 'default' => 'debug'],
        'timezone' => ['config' => 'nexusdav.default_timezone', 'env' => 'NEXUSDAV_TIMEZONE', 'type' => 'timezone', 'default' => 'UTC'],
        'time_format' => ['config' => 'nexusdav.time_format', 'env' => 'NEXUSDAV_TIME_FORMAT', 'type' => 'choice', 'options' => ['24', '12'], 'default' => '24'],
        'week_start' => ['config' => 'nexusdav.week_start', 'env' => 'NEXUSDAV_WEEK_START', 'type' => 'int', 'min' => 0, 'max' => 6, 'default' => 1],
        'rate_limit_max_attempts' => ['config' => 'nexusdav.rate_limit.max_attempts', 'env' => 'NEXUSDAV_RATE_LIMIT_MAX_ATTEMPTS', 'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 5],
        'rate_limit_per_address' => ['config' => 'nexusdav.rate_limit.max_attempts_per_address', 'env' => 'NEXUSDAV_RATE_LIMIT_PER_ADDRESS', 'type' => 'int', 'min' => 1, 'max' => 1000, 'default' => 20],
        'rate_limit_decay_seconds' => ['config' => 'nexusdav.rate_limit.decay_seconds', 'env' => 'NEXUSDAV_RATE_LIMIT_DECAY_SECONDS', 'type' => 'int', 'min' => 10, 'max' => 3600, 'default' => 60],
        'session_lifetime' => ['config' => 'session.lifetime', 'env' => 'SESSION_LIFETIME', 'type' => 'int', 'min' => 5, 'max' => 10080, 'default' => 120],
        'session_secure_cookie' => ['config' => 'session.secure', 'env' => 'SESSION_SECURE_COOKIE', 'type' => 'bool'],
        'diagnostics_retention_days' => ['config' => 'nexusdav.diagnostics.retention_days', 'env' => 'NEXUSDAV_DIAGNOSTICS_RETENTION_DAYS', 'type' => 'int', 'min' => 1, 'max' => 365, 'default' => 30],
        'notification_seconds' => ['config' => 'nexusdav.notifications.seconds', 'env' => 'NEXUSDAV_NOTIFICATION_SECONDS', 'type' => 'int', 'min' => 0, 'max' => 300, 'default' => 6],
        'sync_history_days' => ['config' => 'nexusdav.sync_history.retention_days', 'env' => 'NEXUSDAV_SYNC_HISTORY_DAYS', 'type' => 'int', 'min' => 1, 'max' => 3650, 'default' => 90],
    ];

    private const CACHE_KEY = 'nexusdav.settings.v1';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $stored = null;

    /**
     * Current value, after the environment and saved values are applied.
     */
    public function get(string $key): mixed
    {
        $definition = $this->definition($key);

        return $this->normalize($definition, config($definition['config'])) ?? ($definition['default'] ?? null);
    }

    public function isLocked(string $key): bool
    {
        $set = config('nexusdav.env_set', []);

        return is_array($set) && in_array($this->definition($key)['env'], $set, true);
    }

    public function envName(string $key): string
    {
        return $this->definition($key)['env'];
    }

    /**
     * Saves the given values. Locked and unknown keys are ignored, so a
     * forged request cannot override the environment.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $writes = [];

        foreach ($values as $key => $raw) {
            if (! isset(self::DEFINITIONS[$key]) || $this->isLocked($key)) {
                continue;
            }

            $value = $this->normalize(self::DEFINITIONS[$key], $raw);

            if ($value === null) {
                throw new InvalidArgumentException('The value for '.$key.' is not valid.');
            }

            $writes[$key] = $value;
        }

        foreach ($writes as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR)]);
        }

        $this->forget();
        $this->apply();
    }

    /**
     * Puts saved values into config for this request. Values that come from
     * the environment are only normalized. A value that is not valid falls
     * back to the default.
     */
    public function apply(): void
    {
        $stored = $this->stored();

        foreach (self::DEFINITIONS as $key => $definition) {
            $value = null;

            if (! $this->isLocked($key) && array_key_exists($key, $stored)) {
                $value = $this->normalize($definition, $stored[$key]);
            }

            $value ??= $this->normalize($definition, config($definition['config']));
            $value ??= $definition['default'] ?? null;

            if ($value === null) {
                continue;
            }

            config([$definition['config'] => $value]);

            if ($key === 'log_level') {
                $this->applyLogLevel((string) $value);
            }
        }
    }

    public function forget(): void
    {
        $this->stored = null;

        try {
            $this->cache()->forget(self::CACHE_KEY);
        } catch (Throwable) {
            // A missing cache directory must not stop a save.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            $values = $this->cache()->rememberForever(self::CACHE_KEY, function (): array {
                $values = [];

                foreach (Setting::query()->pluck('value', 'key') as $key => $json) {
                    $values[(string) $key] = json_decode((string) $json, true);
                }

                return $values;
            });
        } catch (Throwable) {
            // Before the first migration the table does not exist yet.
            return [];
        }

        return $this->stored = $values;
    }

    private function cache(): Repository
    {
        $store = config('nexusdav.settings_cache_store', 'file');

        return Cache::store(is_string($store) && $store !== '' ? $store : 'file');
    }

    private function applyLogLevel(string $level): void
    {
        $channels = config('logging.channels', []);

        if (! is_array($channels)) {
            return;
        }

        foreach ($channels as $name => $channel) {
            if ($name !== 'slack' && is_array($channel) && array_key_exists('level', $channel)) {
                config(['logging.channels.'.$name.'.level' => $level]);
            }
        }
    }

    /**
     * @return array{config: string, env: string, type: string, default?: mixed, min?: int, max?: int, options?: list<string>}
     */
    private function definition(string $key): array
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new InvalidArgumentException('Unknown setting '.$key.'.');
        }

        return self::DEFINITIONS[$key];
    }

    /**
     * @param  array{config: string, env: string, type: string, default?: mixed, min?: int, max?: int, options?: list<string>}  $definition
     */
    private function normalize(array $definition, mixed $raw): mixed
    {
        switch ($definition['type']) {
            case 'bool':
                if (is_bool($raw)) {
                    return $raw;
                }

                return is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

            case 'int':
                if (is_string($raw) && preg_match('/^\s*-?\d{1,9}\s*$/', $raw) === 1) {
                    $raw = (int) $raw;
                }

                if (! is_int($raw)) {
                    return null;
                }

                if ($raw < ($definition['min'] ?? PHP_INT_MIN) || $raw > ($definition['max'] ?? PHP_INT_MAX)) {
                    return null;
                }

                return $raw;

            case 'choice':
                if (! is_scalar($raw)) {
                    return null;
                }

                $raw = strtolower(trim((string) $raw));

                return in_array($raw, $definition['options'] ?? [], true) ? $raw : null;

            case 'timezone':
                return is_string($raw) && in_array($raw, timezone_identifiers_list(), true) ? $raw : null;

            case 'locale':
                $locale = is_string($raw) ? trim($raw) : '';

                return app(LocaleCatalog::class)->supports($locale) ? $locale : null;
        }

        return null;
    }
}

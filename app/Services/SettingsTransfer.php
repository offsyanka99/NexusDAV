<?php

namespace App\Services;

use App\Support\LocaleCatalog;
use InvalidArgumentException;

/**
 * Export and import of the panel settings as JSON (PR 44 and PR 45 of the
 * Version 1.x.x plan).
 *
 * The file holds every setting of SettingsService::DEFINITIONS that the
 * environment does not set and that is not secret. A value equal to the
 * setting's default is written as null ("use the default"), so an import
 * does not pin today's default on another server; the panel pages save every
 * field, so a saved row does not mean the admin changed it. Nothing else of
 * the settings table is exported (the WebDAV-Push keys), and no users or
 * data.
 *
 * An import is shown first: what would change, what is already the same and
 * what is skipped, with the reason. Applying it goes through
 * SettingsService::replace() in one transaction.
 */
final class SettingsTransfer
{
    public const FORMAT = 'nexusdav-settings';

    public const FORMAT_VERSION = 1;

    public const MAX_BYTES = 256 * 1024;

    public const SKIP_ENVIRONMENT = 'environment';

    public const SKIP_INVALID = 'invalid';

    public const SKIP_UNKNOWN = 'unknown';

    public const SKIP_SECRET = 'secret';

    public const SKIP_HTTPS = 'https';

    /**
     * @var array<string, array{config: string, env: string, type: string, default?: mixed, min?: int, max?: int, options?: list<string>, secret?: bool}>
     */
    private array $definitions;

    /**
     * @param  array<string, array{config: string, env: string, type: string, default?: mixed, min?: int, max?: int, options?: list<string>, secret?: bool}>|null  $definitions
     */
    public function __construct(private SettingsService $settings, ?array $definitions = null)
    {
        $this->definitions = $definitions ?? SettingsService::DEFINITIONS;
    }

    /**
     * @return array{format: string, format_version: int, app_version: string, exported_at: string, settings: array<string, mixed>}
     */
    public function export(): array
    {
        $values = [];

        foreach ($this->definitions as $key => $definition) {
            if (($definition['secret'] ?? false) || $this->settings->isLocked($key)) {
                continue;
            }

            $values[$key] = $this->exported($key);
        }

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'app_version' => (string) config('nexusdav.version', 'dev'),
            'exported_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'settings' => $values,
        ];
    }

    public function fileName(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = trim((string) preg_replace('/[^a-z0-9.-]+/', '-', strtolower(is_string($host) ? $host : '')), '.-');
        $host = $host === '' ? 'nexusdav' : $host;

        return 'nexusdav-settings-'.$host.'-'.now()->format('Y-m-d').'.json';
    }

    /**
     * The settings of a file, or an error the admin sees as it is.
     *
     * @return array<string, mixed>
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is larger than 256 KB.');
        }

        try {
            $file = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('This is not a NexusDAV settings file.');
        }

        if (! is_array($file) || ($file['format'] ?? null) !== self::FORMAT || ! is_int($file['format_version'] ?? null) || $file['format_version'] < 1
            || ! is_array($file['settings'] ?? null) || ($file['settings'] !== [] && array_is_list($file['settings']))) {
            throw new InvalidArgumentException('This is not a NexusDAV settings file.');
        }

        if ($file['format_version'] > self::FORMAT_VERSION) {
            $version = is_string($file['app_version'] ?? null) ? mb_substr($file['app_version'], 0, 32) : '?';

            throw new InvalidArgumentException('This file comes from a newer NexusDAV ('.$version.'). Upgrade this server first.');
        }

        return $file['settings'];
    }

    /**
     * What applying the settings would do. Values are the stored form; null
     * means the default.
     *
     * @param  array<string, mixed>  $settings
     * @return array{changes: array<string, mixed>, same: list<string>, skipped: array<string, string>}
     */
    public function plan(array $settings, bool $secureRequest): array
    {
        $changes = [];
        $same = [];
        $skipped = [];

        foreach ($settings as $key => $raw) {
            $key = (string) $key;
            $definition = $this->definitions[$key] ?? null;

            if ($definition === null) {
                $skipped[$key] = self::SKIP_UNKNOWN;

                continue;
            }

            if ($definition['secret'] ?? false) {
                $skipped[$key] = self::SKIP_SECRET;

                continue;
            }

            if ($this->settings->isLocked($key)) {
                $skipped[$key] = self::SKIP_ENVIRONMENT;

                continue;
            }

            $value = $raw === null ? null : $this->settings->normalizeValue($key, $raw);

            if ($raw !== null && $value === null) {
                $skipped[$key] = self::SKIP_INVALID;

                continue;
            }

            if ($key === 'session_secure_cookie' && $this->effective($key, $value) === true && ! $secureRequest
                && $this->settings->get($key) !== true) {
                // The panel would sign you out at once, as on the page.
                $skipped[$key] = self::SKIP_HTTPS;

                continue;
            }

            if ($this->differs($key, $value)) {
                $changes[$key] = $value;
            } else {
                $same[] = $key;
            }
        }

        return ['changes' => $changes, 'same' => $same, 'skipped' => $skipped];
    }

    /**
     * Applies what plan() lists as changes.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string> the changed settings
     */
    public function import(array $settings, bool $secureRequest): array
    {
        $changes = $this->plan($settings, $secureRequest)['changes'];

        if ($changes !== []) {
            $this->settings->replace($changes);
        }

        return array_keys($changes);
    }

    public function label(string $key): string
    {
        return match ($key) {
            'caldav' => __('CalDAV'),
            'carddav' => __('CardDAV'),
            'tasks' => __('Tasks (VTODO)'),
            'notes' => __('Notes (VJOURNAL)'),
            'sharing' => __('Sharing'),
            'files' => __('Files'),
            'files_max_upload_mb' => __('Maximum upload size'),
            'push' => __('WebDAV-Push'),
            'push_files' => __('Include Files in push'),
            'push_max_subscriptions' => __('Push subscriptions per user'),
            'push_allow_private' => __('Allow private networks'),
            'push_log_level' => __('Push log level'),
            'default_locale' => __('Server default language'),
            'log_level' => __('Log level'),
            'timezone' => __('Server time zone'),
            'time_format' => __('Time format'),
            'week_start' => __('Week starts on'),
            'rate_limit_max_attempts' => __('Failures per username'),
            'rate_limit_per_address' => __('Failures per address'),
            'rate_limit_decay_seconds' => __('Window'),
            'session_lifetime' => __('Session lifetime'),
            'session_secure_cookie' => __('Secure cookies'),
            'diagnostics_retention_days' => __('Keep failed logins and DAV errors for'),
            'notification_seconds' => __('Close messages after'),
            'sync_history_days' => __('Keep sync history for'),
            default => $key,
        };
    }

    /**
     * A value as the pages show it. Null is the default.
     */
    public function display(string $key, mixed $value): string
    {
        if ($value === null) {
            $default = isset($this->definitions[$key]) ? $this->settings->defaultValue($key) : null;

            return $default === null ? __('Default') : __('Default (:value)', ['value' => $this->display($key, $default)]);
        }

        if (is_bool($value)) {
            return $value ? __('On') : __('Off');
        }

        return match ($key) {
            'default_locale' => app(LocaleCatalog::class)->all()[(string) $value] ?? (string) $value,
            'time_format' => (string) $value === '12' ? __('12-hour') : __('24-hour'),
            'week_start' => match ((int) $value) {
                0 => __('Sunday'),
                1 => __('Monday'),
                2 => __('Tuesday'),
                3 => __('Wednesday'),
                4 => __('Thursday'),
                5 => __('Friday'),
                default => __('Saturday'),
            },
            'log_level', 'push_log_level' => ucfirst((string) $value),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    public function reason(string $skip): string
    {
        return match ($skip) {
            self::SKIP_ENVIRONMENT => __('Set by the environment on this server'),
            self::SKIP_INVALID => __('Not valid on this server'),
            self::SKIP_SECRET => __('Secret: enter it on this server'),
            self::SKIP_HTTPS => __('Open the panel over HTTPS before you turn this on.'),
            default => __('Unknown setting'),
        };
    }

    /**
     * Null when the value equals the default; a setting without a fixed
     * default is null unless a value is saved.
     */
    private function exported(string $key): mixed
    {
        $value = $this->settings->get($key);
        $default = $this->settings->defaultValue($key);

        if ($default === null) {
            return $this->settings->isSaved($key) ? $value : null;
        }

        return $value === $default ? null : $value;
    }

    /**
     * The value the setting would have; null for a setting without a fixed
     * default left to its default.
     */
    private function effective(string $key, mixed $value): mixed
    {
        return $value ?? $this->settings->defaultValue($key);
    }

    private function differs(string $key, mixed $value): bool
    {
        if ($value === null && $this->settings->defaultValue($key) === null) {
            return $this->settings->isSaved($key);
        }

        return $this->effective($key, $value) !== $this->settings->get($key);
    }
}

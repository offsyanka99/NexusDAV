<?php

use App\Services\SettingsService;

/*
| Settings that the admin panel can change (Settings and Configuration pages)
| read their environment variable here. When that variable is set, it wins
| and the panel shows the field as locked; otherwise a value saved in the
| panel replaces the default at boot (SettingsService::apply()).
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | Shown on the Dashboard. The image sets NEXUSDAV_VERSION at build time.
    |
    */

    'version' => env('NEXUSDAV_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Server time
    |--------------------------------------------------------------------------
    |
    | default_timezone applies when a user's timezone column is null. It is
    | also the zone for new calendars and the display zone of the admin panel.
    | time_format is "24" or "12". week_start is 0 (Sunday) to 6 (Saturday).
    |
    */

    'default_timezone' => env('NEXUSDAV_TIMEZONE', 'UTC'),

    'time_format' => env('NEXUSDAV_TIME_FORMAT', '24'),

    'week_start' => env('NEXUSDAV_WEEK_START', 1),

    'default_locale' => env('NEXUSDAV_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Peers whose X-Forwarded-* headers are believed. Unset or blank means
    | the private ranges, which covers a reverse proxy in the same Docker
    | network or LAN. "*" trusts every peer; the limiter then cannot tell
    | real client addresses apart, so the per-address limit is turned off.
    |
    */

    'trusted_proxies' => (static function (): string {
        $value = env('TRUSTED_PROXIES');

        if (! is_string($value) || trim($value) === '') {
            return '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1,fc00::/7';
        }

        return trim($value);
    })(),

    /*
    |--------------------------------------------------------------------------
    | DAV features
    |--------------------------------------------------------------------------
    |
    | Read by DavServerFactory. CalDAV and CardDAV decide which roots and
    | plugins are loaded. Tasks (VTODO) and notes (VJOURNAL) decide which
    | calendar components a calendar may hold. All are on by default.
    |
    */

    'features' => [
        'caldav' => env('NEXUSDAV_CALDAV', true),
        'carddav' => env('NEXUSDAV_CARDDAV', true),
        'tasks' => env('NEXUSDAV_TASKS', true),
        'notes' => env('NEXUSDAV_NOTES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Counted per IP and username. Failures from one address do not block
    | that username from a different address. max_attempts_per_address
    | counts failures from one address across all usernames; it applies only
    | when TRUSTED_PROXIES is an explicit list, not "*".
    |
    */

    'rate_limit' => [
        'max_attempts' => env('NEXUSDAV_RATE_LIMIT_MAX_ATTEMPTS', 5),
        'max_attempts_per_address' => env('NEXUSDAV_RATE_LIMIT_PER_ADDRESS', 20),
        'decay_seconds' => env('NEXUSDAV_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    |
    | Rejected logins are stored in diagnostic_events. Expired rows are
    | deleted when a new row is written, at most once per prune interval.
    | The container has no scheduler. One address gets at most
    | max_rows_per_address rows per collapse window; further failures from it
    | are counted on one "many usernames" row.
    |
    */

    'diagnostics' => [
        'retention_days' => env('NEXUSDAV_DIAGNOSTICS_RETENTION_DAYS', 30),
        'prune_interval_seconds' => 3600,
        'collapse_seconds' => 60,
        'max_rows_per_address' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin panel notifications
    |--------------------------------------------------------------------------
    |
    | Seconds before a success or info message closes by itself. 0 keeps it
    | until the admin closes it. Errors and warnings always stay.
    |
    */

    'notifications' => [
        'seconds' => env('NEXUSDAV_NOTIFICATION_SECONDS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync history
    |--------------------------------------------------------------------------
    |
    | Change rows for calendars and address books are deleted on a later DAV
    | write, at most once per prune interval, one batch per table. A client
    | whose token is older than the rows still kept is told to sync again.
    |
    */

    'sync_history' => [
        'retention_days' => env('NEXUSDAV_SYNC_HISTORY_DAYS', 90),
        'prune_interval_seconds' => 3600,
        'prune_batch' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel settings
    |--------------------------------------------------------------------------
    |
    | env_set lists the environment variables of panel settings that are
    | set, so those fields stay read-only. settings_cache_store keeps the
    | saved values out of the database on each request; saving clears it.
    |
    */

    'env_set' => array_values(array_filter(
        array_column(SettingsService::DEFINITIONS, 'env'),
        static function (string $name): bool {
            $value = env($name);

            return $value !== null && $value !== '';
        },
    )),

    'settings_cache_store' => env('NEXUSDAV_SETTINGS_CACHE_STORE', 'file'),

];

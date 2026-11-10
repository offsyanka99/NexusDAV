<?php

namespace App\Listeners;

use App\Services\SettingsService;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Each job runs with the settings saved in the panel now, not those of
 * when the worker started: switching WebDAV-Push on, or a new push log
 * level, applies to the next job. Found by Laravel's listener discovery.
 */
class ReloadSettingsForJob
{
    public function __construct(private SettingsService $settings) {}

    public function handle(JobProcessing $event): void
    {
        $this->settings->reload();
    }
}

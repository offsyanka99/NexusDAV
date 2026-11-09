<?php

namespace App\Providers;

use App\Services\AuthThrottle;
use App\Services\Dav\DavAuthBackend;
use App\Services\DiagnosticEventWriter;
use App\Services\Push\PushChanges;
use App\Services\Push\PushKeys;
use App\Services\Push\PushResolver;
use App\Services\Push\PushSender;
use App\Services\Push\SystemPushResolver;
use App\Services\Push\WebPushSender;
use App\Services\SettingsService;
use App\Support\LocaleCatalog;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DavAuthBackend::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(LocaleCatalog::class);

        // Per request, and per job in the queue worker.
        $this->app->scoped(PushKeys::class);
        $this->app->scoped(PushChanges::class);
        $this->app->bind(PushSender::class, WebPushSender::class);
        $this->app->bind(PushResolver::class, SystemPushResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Saved panel settings replace config defaults before anything reads them.
        $this->app->make(SettingsService::class)->apply();

        Event::listen(Failed::class, function (Failed $event): void {
            app(DiagnosticEventWriter::class)->recordAdmin($event);
            app(AuthThrottle::class)->hitAddress();
        });

        $this->configurePanelTime();
    }

    /**
     * The panel shows times in the server time zone, in the configured
     * 12- or 24-hour format, and pickers start the week on the configured
     * day. Closures read config when used, so a saved change applies to the
     * next request without a restart.
     */
    private function configurePanelTime(): void
    {
        FilamentTimezone::set(fn (): string => (string) config('nexusdav.default_timezone', 'UTC'));

        $twelveHour = fn (): bool => config('nexusdav.time_format') === '12';

        // Success and info messages close after the configured time (0 keeps
        // them); errors and warnings stay until closed. An explicit
        // ->persistent() or ->duration() on a message still wins.
        Notification::configureUsing(function (Notification $notification): void {
            $notification->duration(function () use ($notification): int|string {
                $seconds = (int) config('nexusdav.notifications.seconds', 6);

                if ($seconds <= 0 || in_array($notification->getStatus(), ['danger', 'warning'], true)) {
                    return 'persistent';
                }

                return $seconds * 1000;
            });
        });

        Table::configureUsing(function (Table $table) use ($twelveHour): void {
            $table
                ->defaultDateTimeDisplayFormat(fn (): string => $twelveHour() ? 'M j, Y g:i:s A' : 'M j, Y H:i:s')
                ->defaultTimeDisplayFormat(fn (): string => $twelveHour() ? 'g:i:s A' : 'H:i:s');
        });

        Schema::configureUsing(function (Schema $schema) use ($twelveHour): void {
            $schema
                ->defaultDateTimeDisplayFormat(fn (): string => $twelveHour() ? 'M j, Y g:i:s A' : 'M j, Y H:i:s')
                ->defaultTimeDisplayFormat(fn (): string => $twelveHour() ? 'g:i:s A' : 'H:i:s');
        });

        DateTimePicker::configureUsing(function (DateTimePicker $picker) use ($twelveHour): void {
            // The browser's own picker (Filament's default) follows the browser's
            // language, not these settings.
            $picker
                ->native(false)
                ->seconds(false)
                ->defaultDateTimeDisplayFormat(fn (): string => $twelveHour() ? 'M j, Y g:i A' : 'M j, Y H:i')
                ->defaultDateTimeWithSecondsDisplayFormat(fn (): string => $twelveHour() ? 'M j, Y g:i:s A' : 'M j, Y H:i:s')
                ->defaultTimeDisplayFormat(fn (): string => $twelveHour() ? 'g:i A' : 'H:i')
                ->defaultTimeWithSecondsDisplayFormat(fn (): string => $twelveHour() ? 'g:i:s A' : 'H:i:s')
                ->firstDayOfWeek((int) config('nexusdav.week_start', 1));
        });
    }
}

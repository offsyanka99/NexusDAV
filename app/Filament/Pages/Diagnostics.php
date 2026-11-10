<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Diagnostics\AppPasswordsTable;
use App\Filament\Widgets\Diagnostics\DavErrorsTable;
use App\Filament\Widgets\Diagnostics\FailedLoginsTable;
use App\Filament\Widgets\Diagnostics\PushSubscriptionsTable;
use App\Filament\Widgets\Diagnostics\QueueWorkerCard;
use App\Services\DiagnosticsSummary;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Read-only view of recent problems: failed logins, DAV errors, every app
 * password and every push subscription (each with revoke), and the queue
 * worker. The 24-hour numbers match the Dashboard.
 */
class Diagnostics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'diagnostics';

    public static function getNavigationLabel(): string
    {
        return __('Diagnostics');
    }

    public function getTitle(): string
    {
        return __('Diagnostics');
    }

    public function getSubheading(): string
    {
        $summary = app(DiagnosticsSummary::class);

        $logins = $summary->failedLogins();
        $errors = $summary->davErrors();

        return __('Last :hours hours: :logins, :errors.', [
            'hours' => DiagnosticsSummary::WINDOW_HOURS,
            'logins' => trans_choice(':count failed login|:count failed logins', $logins, ['count' => Number::format($logins)]),
            'errors' => trans_choice(':count DAV error|:count DAV errors', $errors, ['count' => Number::format($errors)]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                FailedLoginsTable::class,
                DavErrorsTable::class,
                AppPasswordsTable::class,
                QueueWorkerCard::class,
                PushSubscriptionsTable::class,
            ])),
        ]);
    }
}

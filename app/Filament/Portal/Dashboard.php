<?php

namespace App\Filament\Portal;

use App\Models\User;
use App\Portal\PortalApps;
use App\Portal\PortalPage;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * The portal's home page at /: a welcome line and the cards of the visible
 * apps (PortalApp::cards()), in app order.
 */
class Dashboard extends PortalPage
{
    protected static ?string $slug = 'dashboard';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Text::make(function (): string {
                    $user = Filament::auth()->user();

                    return __('Welcome, :name.', ['name' => $user instanceof User ? $user->getFilamentName() : '']);
                }),
                Grid::make(['default' => 1, 'lg' => 2])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents(app(PortalApps::class)->cards())),
            ]);
    }
}

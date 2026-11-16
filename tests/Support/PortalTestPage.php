<?php

namespace Tests\Support;

use App\Portal\PortalPage;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class PortalTestPage extends PortalPage
{
    protected static ?string $slug = 'test-app';

    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make('Test app page')]);
    }
}

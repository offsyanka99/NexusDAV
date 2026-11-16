<?php

namespace App\Filament\Portal;

use App\Portal\PortalPage;
use Filament\Schemas\Schema;

/**
 * Placeholder of the Calendar app; its PR replaces the content.
 */
class Calendar extends PortalPage
{
    protected static ?string $slug = 'calendar';

    public function content(Schema $schema): Schema
    {
        return $this->placeholder($schema);
    }
}

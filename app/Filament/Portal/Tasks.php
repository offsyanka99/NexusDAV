<?php

namespace App\Filament\Portal;

use App\Portal\PortalPage;
use Filament\Schemas\Schema;

/**
 * Placeholder of the Tasks app; its PR replaces the content.
 */
class Tasks extends PortalPage
{
    protected static ?string $slug = 'tasks';

    public function content(Schema $schema): Schema
    {
        return $this->placeholder($schema);
    }
}

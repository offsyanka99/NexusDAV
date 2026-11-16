<?php

namespace App\Filament\Portal;

use App\Portal\PortalPage;
use Filament\Schemas\Schema;

/**
 * Placeholder of the Files app; its PR replaces the content.
 */
class Files extends PortalPage
{
    protected static ?string $slug = 'files';

    public function content(Schema $schema): Schema
    {
        return $this->placeholder($schema);
    }
}

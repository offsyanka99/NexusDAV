<?php

namespace App\Filament\Portal;

use App\Portal\PortalPage;
use Filament\Schemas\Schema;

/**
 * Placeholder of the Contacts app; its PR replaces the content.
 */
class Contacts extends PortalPage
{
    protected static ?string $slug = 'contacts';

    public function content(Schema $schema): Schema
    {
        return $this->placeholder($schema);
    }
}

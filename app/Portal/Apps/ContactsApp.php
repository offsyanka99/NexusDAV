<?php

namespace App\Portal\Apps;

use App\Filament\Portal\Contacts;
use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

final class ContactsApp extends PortalApp
{
    public function id(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return __('Contacts');
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedUserGroup;
    }

    public function sort(): int
    {
        return 40;
    }

    public function switches(): array
    {
        return ['carddav'];
    }

    public function pages(): array
    {
        return [Contacts::class];
    }
}

<?php

namespace App\Portal\Apps;

use App\Filament\Portal\Notes;
use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

final class NotesApp extends PortalApp
{
    public function id(): string
    {
        return 'notes';
    }

    public function label(): string
    {
        return __('Notes');
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedDocumentText;
    }

    public function sort(): int
    {
        return 30;
    }

    public function switches(): array
    {
        return ['caldav', 'notes'];
    }

    public function pages(): array
    {
        return [Notes::class];
    }
}

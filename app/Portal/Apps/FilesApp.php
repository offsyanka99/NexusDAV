<?php

namespace App\Portal\Apps;

use App\Filament\Portal\Files;
use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

final class FilesApp extends PortalApp
{
    public function id(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return __('Files');
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedFolder;
    }

    public function sort(): int
    {
        return 50;
    }

    public function switches(): array
    {
        return ['files'];
    }

    public function pages(): array
    {
        return [Files::class];
    }
}

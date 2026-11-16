<?php

namespace Tests\Support;

use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

/**
 * A portal app that exists only in the tests: a registry class, one page and
 * one Dashboard card, and nothing else.
 */
final class PortalTestApp extends PortalApp
{
    public function id(): string
    {
        return 'test-app';
    }

    public function label(): string
    {
        return 'Test app';
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedBeaker;
    }

    public function sort(): int
    {
        return 25;
    }

    public function switches(): array
    {
        return ['files'];
    }

    public function pages(): array
    {
        return [PortalTestPage::class];
    }

    public function cards(): array
    {
        return [PortalTestCard::class];
    }
}

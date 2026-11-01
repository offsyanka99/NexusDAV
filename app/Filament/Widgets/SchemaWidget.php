<?php

namespace App\Filament\Widgets;

use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A Dashboard card built from Filament schema components, so it is styled
 * without a custom theme. When its data cannot be read, the card shows
 * "Unavailable" and the rest of the page keeps working.
 */
abstract class SchemaWidget extends Widget implements HasSchemas
{
    use InteractsWithSchemas;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.schema-widget';

    abstract protected function heading(): string;

    /**
     * @return array<Component|Entry>
     */
    abstract protected function entries(): array;

    protected function section(Section $section): Section
    {
        return $section;
    }

    public function content(Schema $schema): Schema
    {
        try {
            $entries = $this->entries();
        } catch (Throwable $exception) {
            Log::warning('A Dashboard card could not load', ['widget' => static::class, 'exception' => $exception]);

            $entries = [
                TextEntry::make('unavailable')
                    ->hiddenLabel()
                    ->state(__('Unavailable'))
                    ->badge()
                    ->color('danger')
                    ->icon(Heroicon::ExclamationTriangle),
            ];
        }

        return $schema->components([
            $this->section(Section::make($this->heading())->schema($entries)),
        ]);
    }
}

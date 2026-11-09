<?php

namespace App\Filament\Widgets\Diagnostics;

use App\Models\DiagnosticEvent;
use App\Services\DiagnosticsSummary;
use App\Support\DavStatusCodes;
use App\Support\DiagnosticReason;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 4xx and 5xx answers on /dav, including rejected DAV logins with their
 * reason. Shown on Diagnostics only.
 */
class DavErrorsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Latest DAV errors'))
            ->description(__('Answers with status 400 or higher on /dav. A request without credentials is not an error and is not listed.'))
            ->headerActions([$this->statusCodesAction()])
            ->query(DiagnosticsSummary::davErrorsQuery())
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('method')->label(__('Method')),
                TextColumn::make('path')->label(__('Path'))->wrap()->searchable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (int $state): string => $state >= 500 ? 'danger' : 'warning'),
                TextColumn::make('username')
                    ->label(__('User'))
                    ->formatStateUsing(fn (?string $state): string => $state === null || $state === '' ? '—' : $state)
                    ->searchable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->formatStateUsing(fn (string $state): string => DiagnosticReason::label($state))
                    ->description(fn (DiagnosticEvent $record): ?string => $record->kind === DiagnosticEvent::KIND_AUTH_FAILED ? __('Rejected login') : null),
                TextColumn::make('repeat_count')->label(__('Count'))->numeric(),
                TextColumn::make('request_id')
                    ->label(__('Request ID'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->tooltip(__('Of the first occurrence. Search the container log for it.'))
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->emptyStateHeading(__('No DAV errors'));
    }

    /**
     * The "i" button: what each status code means here and what to look at.
     */
    private function statusCodesAction(): Action
    {
        return Action::make('statusCodes')
            ->label(__('Status codes'))
            ->tooltip(__('What the status codes mean'))
            ->icon(Heroicon::OutlinedInformationCircle)
            ->iconButton()
            ->color('gray')
            ->modalHeading(__('Status codes'))
            ->modalDescription(__('What each status code means on this server, and what to check. A code that is not listed is shown under its standard name in the Reason column.'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(array_map(
                fn (array $code): Section => Section::make($code['code'].' — '.$code['name'])
                    ->description($code['meaning'])
                    ->collapsible()
                    ->collapsed()
                    ->compact()
                    ->schema([
                        TextEntry::make('check_'.$code['code'])
                            ->label(__('What to check'))
                            ->state($code['check']),
                    ]),
                DavStatusCodes::all(),
            ));
    }
}

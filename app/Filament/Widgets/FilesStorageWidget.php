<?php

namespace App\Filament\Widgets;

use App\Services\FileUsage;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

/**
 * How much space the users' files take. Shown only while Files is on, like
 * the other cards of a service that is off. A page load never walks the
 * files: the total comes from the last count, which an admin starts with
 * Refresh (see FileUsage), and the free space is read from the volume.
 */
class FilesStorageWidget extends SchemaWidget implements HasActions
{
    use InteractsWithActions;

    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.files-storage-widget';

    private ?array $snapshot = null;

    private bool $snapshotLoaded = false;

    public static function canView(): bool
    {
        return (bool) app(SettingsService::class)->get('files');
    }

    protected function heading(): string
    {
        return __('Files storage');
    }

    protected function section(Section $section): Section
    {
        return $section
            ->columns(['default' => 2, 'md' => 4])
            ->footer([$this->refreshStorageAction()]);
    }

    protected function entries(): array
    {
        $none = __('Not counted yet');

        // States are closures, read when the card renders. The card is built
        // before a Refresh runs, and must show its result afterwards.
        return [
            TextEntry::make('storage_size')
                ->label(__('Total size'))
                ->state(function () use ($none): string {
                    $last = $this->last();

                    if ($last === null) {
                        return $none;
                    }

                    $size = Number::fileSize($last['bytes'], 0, 2);

                    return $last['complete'] ? $size : __('At least :size', ['size' => $size]);
                })
                ->color(fn (): ?string => $this->last() === null ? 'gray' : null)
                ->weight(fn (): ?string => $this->last() === null ? null : 'bold'),
            TextEntry::make('storage_files')
                ->label(__('Files'))
                ->state(fn (): string => ($last = $this->last()) === null ? '—' : Number::format($last['files'])),
            TextEntry::make('storage_users')
                ->label(__('Users with files'))
                ->state(fn (): string => ($last = $this->last()) === null ? '—' : Number::format($last['users'])),
            TextEntry::make('storage_free')
                ->label(__('Free space'))
                ->state(function (): string {
                    $volume = app(FileUsage::class)->volume();

                    return $volume === null ? '—' : __(':free of :total', [
                        'free' => Number::fileSize($volume['free'], 1),
                        'total' => Number::fileSize($volume['total'], 1),
                    ]);
                }),
            TextEntry::make('storage_counted')
                ->hiddenLabel()
                ->columnSpanFull()
                ->size('sm')
                ->color(fn (): string => ($last = $this->last()) !== null && ! $last['complete'] ? 'warning' : 'gray')
                ->state(function (): string {
                    $last = $this->last();

                    if ($last === null) {
                        return __('The files are counted when you click Refresh. On a large library this can take a while.');
                    }

                    return $last['complete']
                        ? __('Counted :time, in :seconds seconds.', ['time' => $this->time($last['counted_at']), 'seconds' => Number::format($last['seconds'], 1)])
                        : __('Counted :time. Stopped after :seconds seconds, so the real total is higher. Count again when the server is quiet.', ['time' => $this->time($last['counted_at']), 'seconds' => Number::format($last['seconds'], 0)]);
                }),
        ];
    }

    /**
     * The last count, read once per request and read again after a Refresh.
     *
     * @return array{bytes: int, files: int, users: int, counted_at: int, seconds: float, complete: bool}|null
     */
    private function last(): ?array
    {
        if (! $this->snapshotLoaded) {
            $this->snapshot = app(FileUsage::class)->last();
            $this->snapshotLoaded = true;
        }

        return $this->snapshot;
    }

    public function refreshStorageAction(): Action
    {
        return Action::make('refreshStorage')
            ->label(__('Refresh'))
            ->icon(Heroicon::ArrowPath)
            ->link()
            ->requiresConfirmation()
            ->modalHeading(__('Count the files'))
            ->modalDescription(__("This walks every user's files and reads the disk. On a large library it can take up to :seconds seconds. Count now?", ['seconds' => (int) FileUsage::BUDGET_SECONDS]))
            ->modalSubmitActionLabel(__('Count now'))
            ->action(function (): void {
                @set_time_limit(0);

                try {
                    $result = app(FileUsage::class)->scan();
                } catch (Throwable $exception) {
                    Log::warning('The files could not be counted', ['exception' => $exception]);

                    Notification::make()->danger()->title(__('Could not count the files'))->send();

                    return;
                }

                if ($result === null) {
                    Notification::make()->warning()->title(__('The files are already being counted'))->send();

                    return;
                }

                $this->snapshotLoaded = false;
                $size = Number::fileSize($result['bytes'], 0, 2);
                $notification = Notification::make()
                    ->title($result['complete'] ? __('Counted') : __('Counted, but not all of it'))
                    ->body($result['complete']
                        ? __(':size in :files files.', ['size' => $size, 'files' => Number::format($result['files'])])
                        : __('At least :size in :files files. The count stopped after :seconds seconds.', ['size' => $size, 'files' => Number::format($result['files']), 'seconds' => Number::format($result['seconds'], 0)]));

                $result['complete'] ? $notification->success() : $notification->warning();
                $notification->send();
            });
    }

    private function time(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp)->diffForHumans();
    }
}

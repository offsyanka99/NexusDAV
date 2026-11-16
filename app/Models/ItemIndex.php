<?php

namespace App\Models;

use App\Services\Portal\CollectionRef;
use App\Services\Portal\PortalCollections;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One calendar object or contact in the portal's item index (Decision 9).
 * Derived from the DAV rows and kept current by the backends' hooks; never
 * written by a page. Apps list and search with the scopes below, starting
 * from the user's visible collections, and never join the DAV tables by
 * hand.
 *
 * All-day dates and floating times are wall-clock values stored as if they
 * were UTC, with all_day or floating set (for a task, of its due date).
 *
 * @property int $id
 * @property int|null $calendar_id
 * @property int|null $addressbook_id
 * @property string $uri
 * @property string $kind
 * @property string|null $uid
 * @property string|null $title
 * @property string|null $sort_name
 * @property string|null $organization
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $all_day
 * @property bool $floating
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property string|null $status
 * @property string|null $parent_uid
 * @property bool $recurring
 * @property string|null $search_text
 * @property string $etag
 * @property int $indexer_version
 */
class ItemIndex extends Model
{
    public const EVENT = 'event';

    public const TASK = 'task';

    public const NOTE = 'note';

    public const CONTACT = 'contact';

    public $timestamps = false;

    protected $table = 'item_index';

    protected $guarded = ['*'];

    /**
     * Rows of the calendars and address books the user sees now (owned and
     * shared, with the switches applied).
     *
     * @param  Builder<ItemIndex>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $this->scopeInCollections($query, app(PortalCollections::class)->all($user));
    }

    /**
     * Rows of these collections only.
     *
     * @param  Builder<ItemIndex>  $query
     * @param  list<CollectionRef>  $collections
     */
    public function scopeInCollections(Builder $query, array $collections): void
    {
        $calendars = [];
        $books = [];

        foreach ($collections as $ref) {
            if ($ref->isCalendar()) {
                $calendars[] = $ref->id;
            } else {
                $books[] = $ref->id;
            }
        }

        if ($calendars === [] && $books === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($calendars, $books): void {
            if ($calendars !== []) {
                $query->orWhereIn('calendar_id', $calendars);
            }

            if ($books !== []) {
                $query->orWhereIn('addressbook_id', $books);
            }
        });
    }

    /**
     * @param  Builder<ItemIndex>  $query
     * @param  string|list<string>  $kind
     */
    public function scopeOfKind(Builder $query, string|array $kind): void
    {
        $query->whereIn('kind', (array) $kind);
    }

    /**
     * Every word must appear in the search text. Lower-cased in PHP on both
     * sides, so Cyrillic matches on SQLite and PostgreSQL alike; a term with
     * digits also matches the digits-only copy of a phone number.
     *
     * @param  Builder<ItemIndex>  $query
     */
    public function scopeSearch(Builder $query, string $text): void
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return;
        }

        // One group, so the OR below never escapes the caller's other filters.
        $query->where(function (Builder $query) use ($words, $text): void {
            $query->where(function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    $digits = (string) preg_replace('/\D+/', '', $word);

                    $query->where(function (Builder $query) use ($word, $digits): void {
                        $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.self::escapeLike($word).'%']);

                        if (strlen($digits) >= 3 && $digits !== $word) {
                            $query->orWhereRaw("search_text LIKE ? ESCAPE '!'", ['%'.$digits.'%']);
                        }
                    });
                }
            });

            // A phone number typed with spaces ("555 1234") is several words;
            // its digits together also find it.
            $digits = (string) preg_replace('/\D+/', '', $text);

            if (count($words) > 1 && strlen($digits) >= 6 && preg_match('/^[\d\s()+.\/-]+$/', trim($text)) === 1) {
                $query->orWhereRaw("search_text LIKE ? ESCAPE '!'", ['%'.$digits.'%']);
            }
        });
    }

    /**
     * Tasks due in the range; either end may be open.
     *
     * @param  Builder<ItemIndex>  $query
     */
    public function scopeDueBetween(Builder $query, ?DateTimeInterface $from, ?DateTimeInterface $to): void
    {
        $query->whereNotNull('due_at');

        if ($from !== null) {
            $query->where('due_at', '>=', $this->utc($from));
        }

        if ($to !== null) {
            $query->where('due_at', '<', $this->utc($to));
        }
    }

    /**
     * Items whose time overlaps the range: starting before its end, and
     * ending after its start or starting at or after it. The
     * stored first occurrence only: recurring events are expanded by
     * CalendarReader.
     *
     * @param  Builder<ItemIndex>  $query
     */
    public function scopeOverlapping(Builder $query, ?DateTimeInterface $from, ?DateTimeInterface $to): void
    {
        $query->whereNotNull('starts_at');

        if ($to !== null) {
            $query->where('starts_at', '<', $this->utc($to));
        }

        if ($from !== null) {
            $utc = $this->utc($from);
            // A zero-length item (or one without an end) at the start counts.
            $query->where(function (Builder $query) use ($utc): void {
                $query->where('ends_at', '>', $utc)->orWhere('starts_at', '>=', $utc);
            });
        }
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function utc(DateTimeInterface $value): string
    {
        return Carbon::instance($value)->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'all_day' => 'boolean',
            'floating' => 'boolean',
            'recurring' => 'boolean',
            'indexer_version' => 'integer',
        ];
    }
}

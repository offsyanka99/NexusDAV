<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The holiday settings of one calendar. Its events are made by
 * HolidayCalendarService and are read-only for clients.
 *
 * @property int $calendar_id
 * @property string $region
 * @property string $locale
 * @property list<string> $types
 * @property int $from_year
 * @property int $to_year
 * @property string $data_version
 * @property Carbon|null $refreshed_at
 * @property Carbon|null $created_at
 */
class HolidayCalendar extends Model
{
    protected $fillable = ['calendar_id', 'region', 'locale', 'types', 'from_year', 'to_year', 'data_version', 'refreshed_at'];

    protected function casts(): array
    {
        return [
            'calendar_id' => 'integer',
            'types' => 'array',
            'from_year' => 'integer',
            'to_year' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function ownerInstance(): ?CalendarInstance
    {
        return CalendarInstance::query()
            ->where('calendarid', $this->calendar_id)
            ->where('access', CalendarInstance::OWNER)
            ->first();
    }
}

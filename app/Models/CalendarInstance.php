<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One principal's view of a calendar: the owner's (access 1) or a sharee's
 * (access 2 read, 3 read and write). The events and the sync token belong to
 * the calendar, shared by every instance.
 */
class CalendarInstance extends Model
{
    public const OWNER = 1;

    public $timestamps = false;

    protected $table = 'calendarinstances';

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendarid');
    }

    /**
     * The owner's instance of the same calendar.
     *
     * @return HasOne<CalendarInstance, $this>
     */
    public function ownerInstance(): HasOne
    {
        return $this->hasOne(CalendarInstance::class, 'calendarid', 'calendarid')->where('access', self::OWNER);
    }

    /**
     * @return HasMany<CalendarObject, $this>
     */
    public function objects(): HasMany
    {
        return $this->hasMany(CalendarObject::class, 'calendarid', 'calendarid');
    }
}

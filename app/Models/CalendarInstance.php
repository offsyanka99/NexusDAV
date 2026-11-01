<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarInstance extends Model
{
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
     * @return HasMany<CalendarObject, $this>
     */
    public function objects(): HasMany
    {
        return $this->hasMany(CalendarObject::class, 'calendarid', 'calendarid');
    }
}

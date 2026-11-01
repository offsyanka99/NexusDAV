<?php

namespace App\Services\Dav;

use Sabre\CalDAV\CalendarRoot as SabreCalendarRoot;

final class CalendarRoot extends SabreCalendarRoot
{
    /**
     * @param  array<string, mixed>  $principal
     */
    public function getChildForPrincipal(array $principal)
    {
        return new CalendarHome($this->caldavBackend, $principal);
    }
}

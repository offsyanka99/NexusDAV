<?php

namespace App\Services\Portal\Exceptions;

use App\Support\PanelErrorTranslator;

/**
 * Data the CalDAV or CardDAV rules refuse, a component the calendar does
 * not take, or a name the file rules refuse. The message is sabre's (or
 * ours, in the same style), shown as the body.
 */
final class InvalidItem extends PortalException
{
    public function title(): string
    {
        return __('This could not be saved.');
    }

    public function body(): string
    {
        return PanelErrorTranslator::translate($this->getMessage());
    }
}

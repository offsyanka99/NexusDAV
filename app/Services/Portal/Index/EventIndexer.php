<?php

namespace App\Services\Portal\Index;

use App\Models\ItemIndex;
use Sabre\VObject\Component;

/**
 * Events: title, start, end, all-day, floating, recurring, status.
 */
final class EventIndexer implements KindIndexer
{
    public function kind(): string
    {
        return ItemIndex::EVENT;
    }

    public function fields(Component $component, bool $recurring): array
    {
        $start = IndexValues::moment(IndexValues::first($component, 'DTSTART'));
        $end = IndexValues::moment(IndexValues::first($component, 'DTEND'))['at'];

        if ($end === null && $start['at'] !== null) {
            $end = IndexValues::plusDuration($start['at'], IndexValues::first($component, 'DURATION'))
                ?? ($start['all_day'] ? $start['at']->modify('+1 day') : $start['at']);
        }

        return [
            'title' => IndexValues::text(IndexValues::first($component, 'SUMMARY')),
            'starts_at' => IndexValues::format($start['at']),
            'ends_at' => IndexValues::format($end),
            'all_day' => $start['all_day'],
            'floating' => $start['floating'],
            'status' => IndexValues::upper(IndexValues::first($component, 'STATUS')),
            'recurring' => $recurring,
            'search_text' => IndexValues::search([
                IndexValues::string($component, 'SUMMARY'),
                IndexValues::string($component, 'LOCATION'),
                IndexValues::string($component, 'DESCRIPTION'),
                ...IndexValues::values($component, ['CATEGORIES']),
            ]),
        ];
    }
}

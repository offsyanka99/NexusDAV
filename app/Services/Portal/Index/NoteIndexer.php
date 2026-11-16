<?php

namespace App\Services\Portal\Index;

use App\Models\ItemIndex;
use Sabre\VObject\Component;

/**
 * Notes (VJOURNAL): title and date.
 */
final class NoteIndexer implements KindIndexer
{
    public function kind(): string
    {
        return ItemIndex::NOTE;
    }

    public function fields(Component $component, bool $recurring): array
    {
        $date = IndexValues::moment(IndexValues::first($component, 'DTSTART'));

        return [
            'title' => IndexValues::text(IndexValues::first($component, 'SUMMARY')),
            'starts_at' => IndexValues::format($date['at']),
            'all_day' => $date['all_day'],
            'floating' => $date['floating'],
            'status' => IndexValues::upper(IndexValues::first($component, 'STATUS')),
            'recurring' => $recurring,
            'search_text' => IndexValues::search([
                IndexValues::string($component, 'SUMMARY'),
                ...IndexValues::values($component, ['DESCRIPTION', 'CATEGORIES']),
            ]),
        ];
    }
}

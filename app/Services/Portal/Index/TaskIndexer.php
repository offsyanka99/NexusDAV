<?php

namespace App\Services\Portal\Index;

use App\Models\ItemIndex;
use Sabre\VObject\Component;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;

/**
 * Tasks: title, due (all_day and floating describe it), completed, status,
 * parent UID (RELATED-TO with RELTYPE=PARENT, the default), recurring.
 */
final class TaskIndexer implements KindIndexer
{
    public function kind(): string
    {
        return ItemIndex::TASK;
    }

    public function fields(Component $component, bool $recurring): array
    {
        $due = IndexValues::moment(IndexValues::first($component, 'DUE'));

        return [
            'title' => IndexValues::text(IndexValues::first($component, 'SUMMARY')),
            'due_at' => IndexValues::format($due['at']),
            'all_day' => $due['all_day'],
            'floating' => $due['floating'],
            'completed_at' => IndexValues::format(IndexValues::moment(IndexValues::first($component, 'COMPLETED'))['at']),
            'status' => IndexValues::upper(IndexValues::first($component, 'STATUS')),
            'parent_uid' => $this->parent($component),
            'recurring' => $recurring,
            'search_text' => IndexValues::search([
                IndexValues::string($component, 'SUMMARY'),
                IndexValues::string($component, 'LOCATION'),
                IndexValues::string($component, 'DESCRIPTION'),
                ...IndexValues::values($component, ['CATEGORIES']),
            ]),
        ];
    }

    private function parent(Component $component): ?string
    {
        foreach ($component->select('RELATED-TO') as $related) {
            if (! $related instanceof Property) {
                continue;
            }

            $parameter = $related->offsetGet('RELTYPE');
            $type = $parameter instanceof Parameter ? strtoupper((string) $parameter->getValue()) : 'PARENT';

            if ($type === 'PARENT') {
                return IndexValues::text($related, 200);
            }
        }

        return null;
    }
}

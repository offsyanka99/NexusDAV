<?php

namespace App\Services\Portal\Index;

use Sabre\VObject\Component;

/**
 * The fields of one kind of item (event, task, note, contact) for its
 * item_index row. $component is the master component of a calendar object,
 * or the vCard; $recurring is whether the calendar object holds a series.
 */
interface KindIndexer
{
    public function kind(): string;

    /**
     * @return array<string, mixed> item_index columns
     */
    public function fields(Component $component, bool $recurring): array;
}

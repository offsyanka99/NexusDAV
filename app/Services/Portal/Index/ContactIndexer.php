<?php

namespace App\Services\Portal\Index;

use App\Models\ItemIndex;
use Sabre\VObject\Component;
use Sabre\VObject\Property;

/**
 * Contacts: the display name (FN), a sort name (N: family then given, else
 * FN, lower-cased), the organisation (first ORG part), the first email and
 * the first phone. The search text also holds every phone's digits, so
 * "555 1234" and "555-1234" find each other.
 */
final class ContactIndexer implements KindIndexer
{
    public function kind(): string
    {
        return ItemIndex::CONTACT;
    }

    public function fields(Component $component, bool $recurring): array
    {
        $name = IndexValues::text(IndexValues::first($component, 'FN'));
        $n = IndexValues::first($component, 'N');
        $parts = $n instanceof Property ? array_map('strval', $n->getParts()) : [];
        $sort = trim(($parts[0] ?? '').' '.($parts[1] ?? ''));
        $org = IndexValues::first($component, 'ORG');
        $organization = $org instanceof Property ? trim((string) ($org->getParts()[0] ?? '')) : '';
        $emails = IndexValues::values($component, ['EMAIL']);
        $phones = array_map(static fn (string $phone): string => (string) preg_replace('/^tel:/i', '', $phone), IndexValues::values($component, ['TEL']));
        $digits = array_values(array_filter(array_map(static fn (string $phone): string => (string) preg_replace('/\D+/', '', $phone), $phones)));

        return [
            'title' => $name,
            'sort_name' => mb_substr(mb_strtolower($sort !== '' ? $sort : (string) $name), 0, 255) ?: null,
            'organization' => $organization === '' ? null : mb_substr($organization, 0, 255),
            'email' => isset($emails[0]) ? mb_substr($emails[0], 0, 255) : null,
            'phone' => isset($phones[0]) ? mb_substr($phones[0], 0, 64) : null,
            'search_text' => IndexValues::search([
                $name,
                implode(' ', $parts),
                ...IndexValues::values($component, ['NICKNAME', 'ORG', 'TITLE', 'NOTE']),
                ...$emails,
                ...$phones,
                ...$digits,
            ]),
        ];
    }
}

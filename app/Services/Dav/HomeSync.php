<?php

namespace App\Services\Dav;

use Sabre\DAV\INode;
use Sabre\DAV\Sync\ISyncCollection;

/**
 * The home has no changelog of its own. The token is the child list, so a
 * repeat report is empty and a removed child is reported as deleted.
 * Names stay strings inside pairs. A PHP array key would turn "2024" into an
 * int, and the next report would reject that token.
 * The token stops at 64KiB. A larger home is not given a token the next
 * report would reject.
 */
trait HomeSync
{
    private const TOKEN_LIMIT = 65536;

    public function getSyncToken()
    {
        return $this->encodeSnapshot($this->snapshot());
    }

    /**
     * @return array{syncToken: string, added: list<string>, modified: list<string>, deleted: list<string>}|null
     */
    public function getChanges($syncToken, $syncLevel, $limit = null)
    {
        $current = $this->snapshot();
        $token = $this->encodeSnapshot($current);

        if ($token === null) {
            return null;
        }

        if ($this->blankToken($syncToken)) {
            $added = [];

            foreach ($current as $pair) {
                $added[] = $pair[0];
            }

            return [
                'syncToken' => $token,
                'added' => $added,
                'modified' => [],
                'deleted' => [],
            ];
        }

        $previous = $this->decodeSnapshot($syncToken);

        if ($previous === null) {
            return null;
        }

        $currentByName = $this->byName($current);
        $previousByName = $this->byName($previous);
        $added = [];
        $modified = [];
        $deleted = [];

        foreach ($currentByName as $key => $childToken) {
            $name = substr($key, 1);

            if (! array_key_exists($key, $previousByName)) {
                $added[] = $name;
            } elseif ($previousByName[$key] !== $childToken) {
                $modified[] = $name;
            }
        }

        foreach (array_keys(array_diff_key($previousByName, $currentByName)) as $key) {
            $deleted[] = substr((string) $key, 1);
        }

        return [
            'syncToken' => $token,
            'added' => $added,
            'modified' => $modified,
            'deleted' => $deleted,
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function snapshot(): array
    {
        $items = [];

        foreach ($this->getChildren() as $child) {
            if (! $child instanceof INode) {
                continue;
            }

            $childToken = '';

            if ($child instanceof ISyncCollection) {
                $value = $child->getSyncToken();
                $childToken = is_scalar($value) ? (string) $value : '';
            }

            $items[] = [$child->getName(), $childToken];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp($left[0], $right[0]);
        });

        return $items;
    }

    /**
     * sabre documents the token as a string and passes null for the first sync.
     */
    private function blankToken(mixed $syncToken): bool
    {
        return ! is_string($syncToken) || $syncToken === '';
    }

    /**
     * A leading NUL keeps the name a string key. "2024" would otherwise be an int.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, string>
     */
    private function byName(array $pairs): array
    {
        $indexed = [];

        foreach ($pairs as $pair) {
            $indexed["\0".$pair[0]] = $pair[1];
        }

        return $indexed;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $snapshot
     */
    private function encodeSnapshot(array $snapshot): ?string
    {
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $token = 'home-1.'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        if (strlen($token) > self::TOKEN_LIMIT) {
            return null;
        }

        return $token;
    }

    /**
     * A client-supplied token is untrusted. Anything that is not our snapshot
     * is an unknown token, which makes the report fail so the client can
     * start again.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function decodeSnapshot(mixed $syncToken): ?array
    {
        if (! is_string($syncToken) || ! str_starts_with($syncToken, 'home-1.') || strlen($syncToken) > self::TOKEN_LIMIT) {
            return null;
        }

        $encoded = substr($syncToken, strlen('home-1.'));
        $padding = (4 - strlen($encoded) % 4) % 4;
        $json = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);

        if (! is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true, 8);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $snapshot = [];

        foreach ($decoded as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2 || ! is_string($pair[0]) || $pair[0] === '' || ! is_string($pair[1])) {
                return null;
            }

            $snapshot[] = [$pair[0], $pair[1]];
        }

        return $snapshot;
    }
}

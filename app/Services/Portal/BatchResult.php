<?php

namespace App\Services\Portal;

use App\Services\Portal\Exceptions\PortalException;

/**
 * The outcome of a batch, item by item, in the order given. A refused item
 * carries the exception that refused it; the other items were written.
 */
final class BatchResult
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public const REFUSED = 'refused';

    /**
     * @var list<array{index: int, status: string, uri: ?string, etag: ?string, uid: ?string, error: ?PortalException}>
     */
    private array $items = [];

    public function add(int $index, string $status, ?string $uri = null, ?string $etag = null, ?string $uid = null, ?PortalException $error = null): void
    {
        $this->items[] = ['index' => $index, 'status' => $status, 'uri' => $uri, 'etag' => $etag, 'uid' => $uid, 'error' => $error];
    }

    /**
     * @return list<array{index: int, status: string, uri: ?string, etag: ?string, uid: ?string, error: ?PortalException}>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function count(string $status): int
    {
        return count(array_filter($this->items, static fn (array $item): bool => $item['status'] === $status));
    }

    /**
     * @return list<array{index: int, status: string, uri: ?string, etag: ?string, uid: ?string, error: ?PortalException}>
     */
    public function refused(): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool => $item['status'] === self::REFUSED));
    }
}

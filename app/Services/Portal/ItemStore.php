<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Portal\Exceptions\AlreadyExists;
use App\Services\Portal\Exceptions\ChangedElsewhere;
use App\Services\Portal\Exceptions\InvalidItem;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Portal\Exceptions\PortalException;
use Illuminate\Support\Str;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Recur\NoInstancesException;

/**
 * Create, update, delete and batch writes of calendar objects or vCards for
 * the portal, through the sabre backend DAV uses, so the sync token moves
 * and WebDAV-Push is queued exactly as for a client's write (Decision 8).
 * The order of the checks is WritePipeline's, then: the ETag, the
 * validation, the UID, the write.
 */
abstract class ItemStore
{
    public const SKIP = 'skip';

    public const REPLACE = 'replace';

    public const FAIL = 'fail';

    public function __construct(
        protected WritePipeline $pipeline,
        protected ItemValidator $validator,
    ) {}

    /**
     * CollectionRef::CALENDAR or CollectionRef::ADDRESS_BOOK.
     */
    abstract protected function type(): string;

    abstract protected function extension(): string;

    abstract protected function validate(string $data, CollectionRef $ref): ValidatedItem;

    /**
     * The stored object at the URI: its ETag (quoted) and UID.
     *
     * @return array{etag: string, uid: ?string}|null
     */
    abstract protected function current(CollectionRef $ref, string $uri): ?array;

    /**
     * Every stored object by UID.
     *
     * @return array<string, array{uri: string, etag: string}>
     */
    abstract protected function byUid(CollectionRef $ref): array;

    /**
     * Every stored URI, including objects without a UID or with a UID that
     * another object already has (a DAV client can store either).
     *
     * @return list<string>
     */
    abstract protected function storedUris(CollectionRef $ref): array;

    /**
     * The URI of another object with this UID, or null.
     */
    abstract protected function uidTakenBy(CollectionRef $ref, string $uid, string $exceptUri): ?string;

    abstract protected function backendCreate(CollectionRef $ref, string $uri, string $data): string;

    abstract protected function backendUpdate(CollectionRef $ref, string $uri, string $data): string;

    abstract protected function backendDelete(CollectionRef $ref, string $uri): void;

    /**
     * Stores the object at the URI as given: the UID, URI and text stay as
     * they are (after sabre's repair), as for an import or a client's PUT.
     */
    public function create(User $user, int $collectionId, string $uri, string $data): ItemResult
    {
        return $this->pipeline->write($user, $this->type(), $collectionId, function (CollectionRef $ref) use ($uri, $data): ItemResult {
            $this->validator->assertUri($uri);

            if ($this->current($ref, $uri) !== null) {
                throw new AlreadyExists;
            }

            $item = $this->validate($data, $ref);

            if ($this->uidTakenBy($ref, $item->uid, $uri) !== null) {
                throw AlreadyExists::uid();
            }

            return new ItemResult($uri, $this->writeNew($ref, $uri, $item->data), $item->uid);
        });
    }

    /**
     * Replaces the object, if it still has the ETag the caller read. The UID
     * cannot change.
     */
    public function update(User $user, int $collectionId, string $uri, string $data, string $etag): ItemResult
    {
        return $this->pipeline->write($user, $this->type(), $collectionId, function (CollectionRef $ref) use ($uri, $data, $etag): ItemResult {
            $current = $this->current($ref, $uri) ?? throw new NotFound;

            if ($current['etag'] !== $etag) {
                throw new ChangedElsewhere;
            }

            $item = $this->validate($data, $ref);

            if ($current['uid'] !== null && $current['uid'] !== $item->uid) {
                throw new InvalidItem('The UID of an item cannot be changed.');
            }

            return new ItemResult($uri, $this->writeExisting($ref, $uri, $item->data), $item->uid);
        });
    }

    /**
     * Deletes the object, if it still has the ETag the caller read. A task
     * or note can be deleted while its switch is off, as on DAV.
     */
    public function delete(User $user, int $collectionId, string $uri, string $etag): void
    {
        $this->pipeline->write($user, $this->type(), $collectionId, function (CollectionRef $ref) use ($uri, $etag): void {
            $current = $this->current($ref, $uri) ?? throw new NotFound;

            if ($current['etag'] !== $etag) {
                throw new ChangedElsewhere;
            }

            $this->backendDelete($ref, $uri);
        });
    }

    /**
     * Many writes to one collection in one transaction under one lock; push
     * merges them into one message per topic. Items are matched to stored
     * objects by UID: SKIP leaves the stored one, REPLACE updates it, FAIL
     * refuses the item. A refused item (not valid, a UID conflict, a stale
     * ETag) does not stop the others. A read-only collection refuses the
     * whole batch; a database error rolls it all back.
     *
     * @param  iterable<int, BatchItem|string>  $items
     */
    public function batch(User $user, int $collectionId, iterable $items, string $existing = self::SKIP): BatchResult
    {
        if (! in_array($existing, [self::SKIP, self::REPLACE, self::FAIL], true)) {
            throw new \InvalidArgumentException('Unknown batch mode '.$existing.'.');
        }

        return $this->pipeline->write($user, $this->type(), $collectionId, function (CollectionRef $ref) use ($items, $existing): BatchResult {
            $result = new BatchResult;
            $stored = $this->byUid($ref);
            // Every taken name, so no item is written over another object's URI:
            // the failed INSERT would roll back the whole batch.
            $uris = array_fill_keys($this->storedUris($ref), true);

            foreach ($items as $index => $item) {
                $item = is_string($item) ? new BatchItem($item) : $item;

                try {
                    $valid = $this->validate($item->data, $ref);
                    $match = $stored[$valid->uid] ?? null;

                    if ($match !== null) {
                        if ($item->etag !== null && $item->etag !== $match['etag']) {
                            throw new ChangedElsewhere;
                        }

                        if ($existing === self::SKIP) {
                            $result->add($index, BatchResult::SKIPPED, $match['uri'], $match['etag'], $valid->uid);

                            continue;
                        }

                        if ($existing === self::FAIL) {
                            throw AlreadyExists::uid();
                        }

                        $etag = $this->writeExisting($ref, $match['uri'], $valid->data);
                        $stored[$valid->uid]['etag'] = $etag;
                        $result->add($index, BatchResult::UPDATED, $match['uri'], $etag, $valid->uid);

                        continue;
                    }

                    if ($item->uri !== null) {
                        $this->validator->assertUri($item->uri);

                        if (isset($uris[$item->uri])) {
                            throw new AlreadyExists;
                        }
                    }

                    $uri = $item->uri ?? $this->uriFor($valid->uid, $uris);
                    $etag = $this->writeNew($ref, $uri, $valid->data);
                    $stored[$valid->uid] = ['uri' => $uri, 'etag' => $etag];
                    $uris[$uri] = true;
                    $result->add($index, BatchResult::CREATED, $uri, $etag, $valid->uid);
                } catch (PortalException $exception) {
                    $result->add($index, BatchResult::REFUSED, $item->uri, null, null, $exception);
                }
            }

            return $result;
        });
    }

    protected function newUid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * A URI from the UID when it is a plain name, else a new UUID.
     *
     * @param  array<string, true>  $taken
     */
    private function uriFor(string $uid, array $taken): string
    {
        $uri = preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+-]{0,180}$/', $uid) === 1 ? $uid.$this->extension() : null;

        while ($uri === null || isset($taken[$uri])) {
            $uri = $this->newUid().$this->extension();
        }

        return $uri;
    }

    /**
     * The backend refuses some objects before any SQL runs: a name over the
     * limit (BadRequest), a recurrence with no instance left, data vobject
     * cannot expand. Those are refusals of this item only.
     */
    private function writeNew(CollectionRef $ref, string $uri, string $data): string
    {
        try {
            return $this->backendCreate($ref, $uri, $data);
        } catch (BadRequest|NoInstancesException|InvalidDataException $exception) {
            throw new InvalidItem($exception->getMessage(), 0, $exception);
        }
    }

    private function writeExisting(CollectionRef $ref, string $uri, string $data): string
    {
        try {
            return $this->backendUpdate($ref, $uri, $data);
        } catch (BadRequest|NoInstancesException|InvalidDataException $exception) {
            throw new InvalidItem($exception->getMessage(), 0, $exception);
        }
    }
}

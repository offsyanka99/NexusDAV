<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\DavWriteLock;
use App\Services\Portal\Exceptions\ReadOnlyCollection;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The order of checks of every calendar and contact write, in one place:
 * the collection is looked up for the user again (NotFound), refused when
 * it is read-only (ReadOnlyCollection), and then the write runs in one
 * transaction holding the lock a DAV write to the collection takes. The
 * store compares the ETag, validates and writes inside $write. Any
 * exception rolls the transaction back and releases the lock.
 */
final class WritePipeline
{
    public function __construct(private PortalCollections $collections) {}

    /**
     * @template TResult
     *
     * @param  Closure(CollectionRef): TResult  $write
     * @return TResult
     */
    public function write(User $user, string $type, int $collectionId, Closure $write): mixed
    {
        $ref = $this->collections->find($user, $type, $collectionId);

        if (! $ref->isWritable()) {
            throw ReadOnlyCollection::for($ref);
        }

        // SQLite's transactions begin IMMEDIATE: the database write lock is
        // taken here, as for a DAV write. PostgreSQL takes the collection lock.
        return DB::transaction(function () use ($ref, $write): mixed {
            if ($ref->isCalendar()) {
                DavWriteLock::lockCalendar($ref->id);
            } else {
                DavWriteLock::lockAddressBook($ref->id);
            }

            return $write($ref);
        });
    }
}

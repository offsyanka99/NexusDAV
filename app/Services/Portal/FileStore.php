<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\FileResource;
use App\Services\Dav\FileStorage;
use App\Services\Dav\PropertyStorageBackend;
use App\Services\Portal\Exceptions\AlreadyExists;
use App\Services\Portal\Exceptions\InvalidItem;
use App\Services\Portal\Exceptions\LockedItem;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Push\PushChanges;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Locks\Backend\PDO as LockBackend;

/**
 * The user's file home in the portal, through FileStorage with the rules of
 * WebDAV: names in Unicode NFC and the same refused names, symbolic links
 * neither listed nor followed, writes that land whole, the Maximum upload
 * size, a path a WebDAV client locked is LockedItem, and WebDAV-Push as the
 * DAV plugin sends it. The home is created on first use.
 *
 * Paths are relative to the home and use "/". A path that tries to leave
 * the home (".." or "." segments, a leading "/", encoded dots or slashes)
 * is NotFound, like a path that does not exist.
 */
final class FileStore
{
    public function __construct(private FileStorage $storage) {}

    /**
     * The folder's files and folders, folders first, then by name.
     *
     * @return list<FileEntry>
     */
    public function list(User $user, string $folder = ''): array
    {
        $target = $this->existing($user, $folder);

        if (! is_dir($target['absolute'])) {
            throw new NotFound;
        }

        $entries = [];

        foreach (new FilesystemIterator($target['absolute'], FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            $name = $entry->getFilename();
            $absolute = $target['absolute'].'/'.$name;

            // As FileDirectory::getChildren(): only names a client could have created.
            if ($this->storage->lookupName($name) !== $name || ! $this->storage->served($absolute)) {
                continue;
            }

            $entries[] = $this->entry($user, $absolute, $this->join($target['relative'], $name));
        }

        usort($entries, static fn (FileEntry $a, FileEntry $b): int => [$b->folder, mb_strtolower($a->name)] <=> [$a->folder, mb_strtolower($b->name)]);

        return $entries;
    }

    public function createFolder(User $user, string $parent, string $name): FileEntry
    {
        $target = $this->newEntry($user, $parent, $name);

        $this->guard(fn () => $this->storage->makeDirectory($target['absolute']));
        app(PushChanges::class)->fileChanged($target['dav'], false);

        return $this->entry($user, $target['absolute'], $target['relative']);
    }

    /**
     * Writes a new file, or replaces the file of that name, with the
     * Maximum upload size. $length is the size the browser announced.
     *
     * @param  resource|string  $data
     */
    public function write(User $user, string $folder, string $name, mixed $data, ?int $length = null): FileEntry
    {
        $parent = $this->existing($user, $folder);

        if (! is_dir($parent['absolute'])) {
            throw new NotFound;
        }

        $name = $this->name($name);
        $target = $this->target($user, $parent, $name);

        if (is_link($target['absolute']) || is_dir($target['absolute'])) {
            throw new AlreadyExists(true);
        }

        $this->assertUnlocked($target['dav'], false);

        $limit = max(1, (int) config('nexusdav.files.max_upload_mb', 128)) * 1048576;

        if ($length !== null && $length > $limit) {
            throw new InvalidItem('The file is larger than the maximum upload size of '.intdiv($limit, 1048576).' MB.');
        }

        $this->storage->expectUpload($limit, $length);

        try {
            $this->guard(fn () => $this->storage->write($target['absolute'], $data));
        } finally {
            $this->storage->expectUpload(null, null);
        }

        app(PushChanges::class)->fileChanged($target['dav'], false);

        return $this->entry($user, $target['absolute'], $target['relative']);
    }

    /**
     * The file's content as a stream; the caller closes it.
     *
     * @return resource
     */
    public function read(User $user, string $path)
    {
        $target = $this->existing($user, $path);

        if (! is_file($target['absolute'])) {
            throw new NotFound;
        }

        $stream = @fopen($target['absolute'], 'rb');

        if ($stream === false) {
            throw new NotFound;
        }

        return $stream;
    }

    public function entryAt(User $user, string $path): FileEntry
    {
        $target = $this->existing($user, $path);

        return $this->entry($user, $target['absolute'], $target['relative']);
    }

    /**
     * Renames a file or folder inside its folder. Returns the new entry.
     */
    public function rename(User $user, string $path, string $name): FileEntry
    {
        $source = $this->existingChild($user, $path);
        $name = $this->name($name);
        $parent = $this->parentOf($user, $source);
        $target = $this->target($user, $parent, $name);

        if ($target['absolute'] === $source['absolute']) {
            return $this->entry($user, $source['absolute'], $source['relative']);
        }

        return $this->relocate($user, $source, $target);
    }

    /**
     * Moves a file or folder into another folder of the home, keeping its name.
     */
    public function move(User $user, string $path, string $folder): FileEntry
    {
        $source = $this->existingChild($user, $path);
        $parent = $this->existing($user, $folder);

        if (! is_dir($parent['absolute'])) {
            throw new NotFound;
        }

        if (is_dir($source['absolute']) && str_starts_with($parent['absolute'].'/', $source['absolute'].'/')) {
            throw new InvalidItem('A folder cannot be moved into itself.');
        }

        $target = $this->target($user, $parent, basename($source['absolute']));

        if ($target['absolute'] === $source['absolute']) {
            return $this->entry($user, $source['absolute'], $source['relative']);
        }

        return $this->relocate($user, $source, $target);
    }

    public function delete(User $user, string $path): void
    {
        $source = $this->existingChild($user, $path);

        $this->assertUnlocked($source['dav'], true);
        $this->guard(fn () => $this->storage->deleteTree($source['absolute']));

        app(PushChanges::class)->fileChanged($source['dav'], true);
        $this->properties()->delete($source['dav']);
    }

    /**
     * @param  array{absolute: string, relative: string, dav: string}  $source
     * @param  array{absolute: string, relative: string, dav: string}  $target
     */
    private function relocate(User $user, array $source, array $target): FileEntry
    {
        if ($this->storage->taken($target['absolute'])) {
            throw new AlreadyExists(true);
        }

        $this->assertUnlocked($source['dav'], true);
        $this->assertUnlocked($target['dav'], false);

        if (! @rename($source['absolute'], $target['absolute'])) {
            throw new InvalidItem('The file or folder could not be moved.');
        }

        clearstatcache();

        // As a WebDAV MOVE: the old path is removed, the new one changed, and
        // the dead properties go with the entry.
        app(PushChanges::class)->fileChanged($source['dav'], true);
        app(PushChanges::class)->fileChanged($target['dav'], false);
        $this->properties()->move($source['dav'], $target['dav']);

        return $this->entry($user, $target['absolute'], $target['relative']);
    }

    /**
     * @return array{absolute: string, relative: string, dav: string}
     */
    private function newEntry(User $user, string $parent, string $name): array
    {
        $folder = $this->existing($user, $parent);

        if (! is_dir($folder['absolute'])) {
            throw new NotFound;
        }

        $target = $this->target($user, $folder, $this->name($name));

        if ($this->storage->taken($target['absolute'])) {
            throw new AlreadyExists(true);
        }

        $this->assertUnlocked($target['dav'], false);

        return $target;
    }

    /**
     * An existing entry other than the home itself.
     *
     * @return array{absolute: string, relative: string, dav: string}
     */
    private function existingChild(User $user, string $path): array
    {
        $target = $this->existing($user, $path);

        if ($target['relative'] === '') {
            throw new InvalidItem('The file home cannot be changed this way.');
        }

        return $target;
    }

    /**
     * @return array{absolute: string, relative: string, dav: string}
     */
    private function existing(User $user, string $path): array
    {
        if (! filter_var(config('nexusdav.features.files', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new NotFound;
        }

        $home = $this->guard(fn (): string => $this->storage->ensureHome((string) $user->username));
        $segments = $this->segments($path);
        $absolute = $home;

        foreach ($segments as $index => $segment) {
            $absolute .= '/'.$segment;
            $last = $index === array_key_last($segments);

            if (! $this->storage->served($absolute) || (! $last && ! is_dir($absolute))) {
                throw new NotFound;
            }
        }

        $relative = implode('/', $segments);

        return ['absolute' => $absolute, 'relative' => $relative, 'dav' => $this->davPath($user, $relative)];
    }

    /**
     * @param  array{absolute: string, relative: string, dav: string}  $parent
     * @return array{absolute: string, relative: string, dav: string}
     */
    private function target(User $user, array $parent, string $name): array
    {
        $relative = $this->join($parent['relative'], $name);

        return ['absolute' => $parent['absolute'].'/'.$name, 'relative' => $relative, 'dav' => $this->davPath($user, $relative)];
    }

    /**
     * @param  array{absolute: string, relative: string, dav: string}  $entry
     * @return array{absolute: string, relative: string, dav: string}
     */
    private function parentOf(User $user, array $entry): array
    {
        $position = strrpos($entry['relative'], '/');

        return $this->existing($user, $position === false ? '' : substr($entry['relative'], 0, $position));
    }

    /**
     * The path's names in NFC, or NotFound for a path that tries to leave the
     * home or names nothing a client could have created.
     *
     * @return list<string>
     */
    private function segments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        if (str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('/%(2e|2f|5c|00)/i', $path) === 1) {
            throw new NotFound;
        }

        $segments = [];

        foreach (explode('/', rtrim($path, '/')) as $segment) {
            try {
                $normal = $this->storage->lookupName($segment);
            } catch (DavException) {
                $normal = null; // "." and ".." are refused outright.
            }

            if ($normal === null) {
                throw new NotFound;
            }

            $segments[] = $normal;
        }

        return $segments;
    }

    /**
     * A name for a new entry, by FileStorage's rules.
     */
    private function name(string $name): string
    {
        return $this->guard(fn (): string => $this->storage->newName($name));
    }

    private function assertUnlocked(string $davPath, bool $withChildren): void
    {
        $locks = new LockBackend(DB::connection()->getPdo());

        if ($locks->getLocks($davPath, $withChildren) !== []) {
            throw new LockedItem;
        }
    }

    private function entry(User $user, string $absolute, string $relative): FileEntry
    {
        clearstatcache(true, $absolute);
        $folder = is_dir($absolute);
        $name = $relative === '' ? (string) $user->username : basename($relative);

        return new FileEntry(
            name: $name,
            path: $relative,
            folder: $folder,
            size: $folder ? null : (int) filesize($absolute),
            modified: (int) filemtime($absolute),
            etag: $folder ? null : (new FileResource($absolute, $user->principal_uri, $this->storage))->getETag(),
        );
    }

    private function davPath(User $user, string $relative): string
    {
        return rtrim('files/'.$user->username.'/'.$relative, '/');
    }

    private function join(string $folder, string $name): string
    {
        return $folder === '' ? $name : $folder.'/'.$name;
    }

    private function properties(): PropertyStorageBackend
    {
        return new PropertyStorageBackend(DB::connection()->getPdo());
    }

    /**
     * FileStorage speaks sabre's exceptions (a refused name, the upload
     * limit, a full disk); the portal shows their message.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DavException $exception) {
            throw new InvalidItem($exception->getMessage(), 0, $exception);
        }
    }
}

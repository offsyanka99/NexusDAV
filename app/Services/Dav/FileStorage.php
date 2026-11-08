<?php

namespace App\Services\Dav;

use FilesystemIterator;
use Normalizer;
use RuntimeException;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;

/**
 * The disk side of Files (PR 28 of the Version 1.x plan): one directory per
 * user under nexusdav.files.path (/data/files in the image), the rules for a
 * name, writes that land whole or not at all, and deletes that never follow a
 * symbolic link.
 *
 * Uploads are written into UPLOADS, a directory beside the homes on the same
 * volume, and renamed into place. A username starts with a letter or digit,
 * so it can never be that name.
 */
final class FileStorage
{
    public const UPLOADS = '.uploads';

    /**
     * Bytes, the name limit of ext4, ZFS and most other filesystems.
     */
    public const MAX_NAME_BYTES = 255;

    /**
     * Uploads left behind by a killed process are removed after this long.
     */
    private const STALE_UPLOAD_SECONDS = 86400;

    public function root(): string
    {
        $path = rtrim((string) config('nexusdav.files.path'), '/');

        if ($path === '') {
            throw new RuntimeException('nexusdav.files.path is not set.');
        }

        return $path;
    }

    public function homePath(string $username): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $username) !== 1) {
            throw new RuntimeException('Not a valid username for a file home.');
        }

        return $this->root().'/'.$username;
    }

    public function homeExists(string $username): bool
    {
        $path = $this->homePath($username);

        return ! is_link($path) && is_dir($path);
    }

    /**
     * Creates the home on first use, owned by the app user.
     */
    public function ensureHome(string $username): string
    {
        $path = $this->homePath($username);

        if (is_link($path)) {
            throw new RuntimeException('The file home of '.$username.' is a symbolic link; it is not served.');
        }

        if (! is_dir($path)) {
            $this->makeDirectory($this->root(), true);
            $this->makeDirectory($path);
        }

        return $path;
    }

    /**
     * Removes a deleted user's home. It is renamed out of the way first, so a
     * user created again with the same name starts with an empty home even if
     * the removal stops half way.
     */
    public function deleteHome(string $username): void
    {
        $path = $this->homePath($username);

        if (! is_link($path) && ! file_exists($path)) {
            return;
        }

        $trash = $this->uploadsDirectory().'/deleted-'.$username.'-'.bin2hex(random_bytes(6));

        if (! @rename($path, $trash)) {
            throw new RuntimeException('Could not move the file home of '.$username.' out of the way.');
        }

        $this->deleteTree($trash);
    }

    /**
     * A name sent by a client for a new file or folder: refused or returned
     * in Unicode NFC, so a name typed on macOS (NFD) and on Linux (NFC) is
     * the same file.
     */
    public function newName(string $name): string
    {
        $this->refuseTraversal($name);

        if (! mb_check_encoding($name, 'UTF-8')) {
            throw new BadRequest('The name is not valid UTF-8.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new BadRequest('The name contains a control character.');
        }

        $normal = Normalizer::normalize($name, Normalizer::FORM_C);

        if (! is_string($normal) || $normal === '') {
            throw new BadRequest('The name is not valid Unicode.');
        }

        if (strlen($normal) > self::MAX_NAME_BYTES) {
            throw new BadRequest('The name is longer than '.self::MAX_NAME_BYTES.' bytes.');
        }

        return $normal;
    }

    /**
     * A name looked up in a folder. Null when no file could have it, so the
     * caller answers 404; "." and ".." are refused outright.
     */
    public function lookupName(string $name): ?string
    {
        try {
            return $this->newName($name);
        } catch (BadRequest) {
            return null;
        }
    }

    /**
     * An entry the server serves: a file or a folder, not a symbolic link,
     * a device, a socket or a pipe.
     */
    public function served(string $path): bool
    {
        return ! is_link($path) && (is_file($path) || is_dir($path));
    }

    /**
     * Anything at the path, including a symbolic link whose target is gone.
     */
    public function taken(string $path): bool
    {
        return is_link($path) || file_exists($path);
    }

    /**
     * Writes the data to a new file in the uploads directory and renames it
     * over the target, so the target is either the old file or the whole new
     * one, never a part. The temporary file is removed on any failure.
     *
     * @param  resource|string|null  $data
     */
    public function write(string $target, mixed $data): void
    {
        $directory = $this->uploadsDirectory();
        $this->removeStaleUploads($directory);

        $temporary = @tempnam($directory, 'upload-');

        if (! is_string($temporary)) {
            throw new RuntimeException('Could not create a temporary file for an upload.');
        }

        try {
            $this->fill($temporary, $data);
            @chmod($temporary, 0666 & ~umask());

            if (is_link($target)) {
                throw new Forbidden('This name cannot be used.');
            }

            if (! @rename($temporary, $target)) {
                throw new RuntimeException('Could not move an upload into place.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        clearstatcache(true, $target);
    }

    public function makeDirectory(string $path, bool $parents = false): void
    {
        if (is_dir($path) && ! is_link($path)) {
            return;
        }

        if (! @mkdir($path, 0750, $parents) && ! (is_dir($path) && ! is_link($path))) {
            throw new RuntimeException('Could not create a directory for Files.');
        }

        clearstatcache(true, $path);
    }

    /**
     * Deletes a file or a folder with everything in it. A symbolic link is
     * removed itself; what it points to is never touched.
     */
    public function deleteTree(string $path): void
    {
        if (is_link($path) || ! is_dir($path)) {
            if ((is_link($path) || file_exists($path)) && ! @unlink($path)) {
                throw new RuntimeException('Could not delete a file.');
            }

            clearstatcache(true, $path);

            return;
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            $this->deleteTree($entry->getPathname());
        }

        if (! @rmdir($path)) {
            throw new RuntimeException('Could not delete a folder.');
        }

        clearstatcache(true, $path);
    }

    private function refuseTraversal(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, "\0")) {
            throw new Forbidden('This name is not allowed.');
        }
    }

    /**
     * @param  resource|string|null  $data
     */
    private function fill(string $path, mixed $data): void
    {
        $out = @fopen($path, 'wb');

        if ($out === false) {
            throw new RuntimeException('Could not open a temporary file for an upload.');
        }

        try {
            if (is_resource($data)) {
                $this->copyStream($data, $out);
            } elseif (is_string($data)) {
                $this->writeString($out, $data);
            } elseif ($data !== null) {
                throw new RuntimeException('Unsupported upload body.');
            }

            if (! @fflush($out)) {
                throw new RuntimeException('Could not write an upload.');
            }
        } finally {
            if (! @fclose($out)) {
                throw new RuntimeException('Could not write an upload.');
            }
        }
    }

    /**
     * Read by read, not stream_copy_to_stream(): that maps a file-backed
     * stream (sabre's php://temp above 2 MB) without setting its end flag,
     * so a short copy could not be told from a whole one.
     *
     * @param  resource  $data
     * @param  resource  $out
     */
    private function copyStream($data, $out): void
    {
        while (! feof($data)) {
            $chunk = @fread($data, 1048576);

            if ($chunk === false || ($chunk === '' && ! feof($data))) {
                throw new RuntimeException('Could not read an upload.');
            }

            $this->writeString($out, $chunk);
        }
    }

    /**
     * @param  resource  $out
     */
    private function writeString($out, string $data): void
    {
        $length = strlen($data);

        for ($written = 0; $written < $length; $written += $count) {
            $count = @fwrite($out, substr($data, $written));

            if ($count === false || $count === 0) {
                throw new RuntimeException('Could not write an upload.');
            }
        }
    }

    private function uploadsDirectory(): string
    {
        $path = $this->root().'/'.self::UPLOADS;
        $this->makeDirectory($this->root(), true);
        $this->makeDirectory($path);

        return $path;
    }

    private function removeStaleUploads(string $directory): void
    {
        $cutoff = time() - self::STALE_UPLOAD_SECONDS;

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            if (str_starts_with($entry->getFilename(), 'upload-') && ! $entry->isLink() && $entry->isFile() && $entry->getMTime() < $cutoff) {
                @unlink($entry->getPathname());
            }
        }
    }
}

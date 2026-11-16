<?php

namespace App\Services\Portal;

/**
 * A file or folder in the user's file home: its name, its path from the
 * home ("" is the home, "Photos/2026/a.jpg" a file), and for a file its
 * size, last change and ETag.
 */
final class FileEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $folder,
        public readonly ?int $size,
        public readonly int $modified,
        public readonly ?string $etag,
    ) {}
}

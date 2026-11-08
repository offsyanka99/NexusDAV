<?php

namespace App\Services\Dav;

use FilesystemIterator;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\FSExt\Directory;
use Sabre\DAV\INode;
use Sabre\DAVACL\IACL;

/**
 * A folder in a file home, on sabre's FSExt\Directory with these changes:
 * names follow FileStorage's rules and are stored in Unicode NFC; symbolic
 * links, devices, sockets and pipes are neither listed nor opened, so a link
 * planted on disk never leads out of the home; uploads land whole or not at
 * all; and deletes remove links without following them.
 */
class FileDirectory extends Directory implements IACL
{
    use FileAcl;

    public function __construct(string $path, string $owner, protected FileStorage $storage)
    {
        parent::__construct($path);
        $this->owner = $owner;
    }

    public function createFile($name, $data = null)
    {
        $path = $this->path.'/'.$this->storage->newName((string) $name);

        if ($this->storage->taken($path)) {
            throw new Forbidden('This name cannot be used.');
        }

        $this->storage->write($path, $data);

        return $this->file($path)->getETag();
    }

    public function createDirectory($name)
    {
        $path = $this->path.'/'.$this->storage->newName((string) $name);

        if ($this->storage->taken($path)) {
            throw new Forbidden('This name cannot be used.');
        }

        $this->storage->makeDirectory($path);
    }

    public function getChild($name)
    {
        $normal = $this->storage->lookupName((string) $name);
        $path = $this->path.'/'.$normal;

        if ($normal === null || ! $this->storage->served($path)) {
            throw new NotFound('File could not be located');
        }

        return is_dir($path) ? $this->directory($path) : $this->file($path);
    }

    public function childExists($name)
    {
        $normal = $this->storage->lookupName((string) $name);

        return $normal !== null && $this->storage->served($this->path.'/'.$normal);
    }

    /**
     * Only names a client could have created are listed: a name that is not
     * NFC, or breaks the name rules, could not be opened by its listed name.
     *
     * @return list<INode>
     */
    public function getChildren()
    {
        $nodes = [];

        foreach (new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            $name = $entry->getFilename();
            $path = $this->path.'/'.$name;

            if ($this->storage->lookupName($name) !== $name || ! $this->storage->served($path)) {
                continue;
            }

            $nodes[] = is_dir($path) ? $this->directory($path) : $this->file($path);
        }

        return $nodes;
    }

    public function delete()
    {
        $this->storage->deleteTree($this->path);
    }

    public function setName($name)
    {
        $this->path = self::rename($this->storage, $this->path, $name);
    }

    public function moveInto($targetName, $sourcePath, INode $sourceNode)
    {
        if (! $sourceNode instanceof self && ! $sourceNode instanceof FileResource) {
            return false;
        }

        $target = $this->path.'/'.$this->storage->newName((string) $targetName);

        if ($sourceNode instanceof self && str_starts_with($target.'/', $sourceNode->path.'/')) {
            throw new Forbidden('A folder cannot be moved into itself.');
        }

        if ($this->storage->taken($target)) {
            throw new Forbidden('This name cannot be used.');
        }

        return @rename($sourceNode->path, $target);
    }

    /**
     * Renames an entry inside its folder and returns the new path.
     */
    public static function rename(FileStorage $storage, string $path, mixed $name): string
    {
        [, $base] = \Sabre\Uri\split((string) $name);
        $target = dirname($path).'/'.$storage->newName((string) $base);

        if ($target === $path) {
            return $path;
        }

        if ($storage->taken($target)) {
            throw new Forbidden('This name cannot be used.');
        }

        if (! @rename($path, $target)) {
            throw new \RuntimeException('Could not rename a file or folder.');
        }

        return $target;
    }

    private function directory(string $path): self
    {
        return new self($path, $this->owner, $this->storage);
    }

    private function file(string $path): FileResource
    {
        return new FileResource($path, $this->owner, $this->storage);
    }
}

<?php

namespace App\Services\Dav;

use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\FSExt\File;
use Sabre\DAVACL\IACL;

/**
 * A file in a file home. sabre's FSExt\File writes in place; put() here
 * writes a new file and renames it over this one.
 */
class FileResource extends File implements IACL
{
    use FileAcl;

    public function __construct(string $path, string $owner, private FileStorage $storage)
    {
        parent::__construct($path);
        $this->owner = $owner;
    }

    /**
     * @param  resource|string|null  $data
     */
    public function put($data)
    {
        $this->storage->write($this->path, $data);

        return $this->getETag();
    }

    /**
     * sabre's PartialUpdate plugin is not loaded; refuse a direct call too.
     */
    public function patch($data, $rangeType, $offset = null)
    {
        throw new MethodNotAllowed('Partial updates are not supported.');
    }

    public function delete()
    {
        $this->storage->deleteTree($this->path);
    }

    public function setName($name)
    {
        $this->path = FileDirectory::rename($this->storage, $this->path, $name);
    }
}

<?php

namespace App\Services\Dav;

use Sabre\DAV\Exception\Forbidden;

/**
 * The top folder of one user's files, /dav/files/<username>/. Its directory
 * is created on first use.
 */
final class FileHome extends FileDirectory
{
    /**
     * @param  array<string, mixed>  $principal
     */
    public function __construct(FileStorage $storage, array $principal)
    {
        $uri = (string) ($principal['uri'] ?? '');
        $username = substr($uri, strlen('principals/'));

        parent::__construct($storage->ensureHome($username), $uri, $storage);
    }

    public function delete()
    {
        throw new Forbidden('The file home cannot be deleted.');
    }

    public function setName($name)
    {
        throw new Forbidden('The file home cannot be renamed.');
    }
}

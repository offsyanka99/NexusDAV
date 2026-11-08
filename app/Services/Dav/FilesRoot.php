<?php

namespace App\Services\Dav;

use Sabre\DAVACL\AbstractPrincipalCollection;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/**
 * /dav/files/. Lists and opens only the signed-in user's home: the principal
 * backend reports every other principal as missing, so another user's home
 * answers 404 like an unknown name.
 */
final class FilesRoot extends AbstractPrincipalCollection
{
    public function __construct(BackendInterface $principalBackend, private FileStorage $storage, string $principalPrefix = 'principals')
    {
        parent::__construct($principalBackend, $principalPrefix);
    }

    public function getName()
    {
        return 'files';
    }

    /**
     * @param  array<string, mixed>  $principalInfo
     */
    public function getChildForPrincipal(array $principalInfo)
    {
        return new FileHome($this->storage, $principalInfo);
    }
}

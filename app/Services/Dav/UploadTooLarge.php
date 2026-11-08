<?php

namespace App\Services\Dav;

use Sabre\DAV\Exception;

/**
 * 413: an upload to a file home is larger than Maximum upload size.
 */
final class UploadTooLarge extends Exception
{
    public static function forLimit(int $bytes): self
    {
        return new self('The file is larger than the upload limit of '.intdiv($bytes, 1048576).' MB.');
    }

    public function getHTTPCode()
    {
        return 413;
    }
}

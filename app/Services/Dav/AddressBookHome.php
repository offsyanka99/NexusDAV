<?php

namespace App\Services\Dav;

use Sabre\CardDAV\AddressBookHome as SabreAddressBookHome;
use Sabre\DAV\Sync\ISyncCollection;

final class AddressBookHome extends SabreAddressBookHome implements ISyncCollection
{
    use HomeSync;
}

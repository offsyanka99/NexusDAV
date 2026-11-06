<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sharee's copy of an address book (docs/design/sharing.md). The cards
 * and the sync token belong to the address book; this row holds the sharee's
 * name and access level.
 *
 * @property int $id
 * @property int $addressbookid
 * @property string $principaluri
 * @property string $uri
 * @property string|null $displayname
 * @property string|null $description
 * @property int $access
 * @property string $share_href
 * @property string|null $share_displayname
 * @property int $share_invitestatus
 */
class AddressBookInstance extends Model
{
    public $timestamps = false;

    protected $table = 'addressbookinstances';

    /**
     * @return BelongsTo<AddressBook, $this>
     */
    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class, 'addressbookid');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'addressbookid' => 'integer',
            'access' => 'integer',
            'share_invitestatus' => 'integer',
        ];
    }
}

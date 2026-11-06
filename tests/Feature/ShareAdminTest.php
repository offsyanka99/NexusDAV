<?php

namespace Tests\Feature;

use App\Exceptions\LastActiveAdminException;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AddressBooksRelationManager;
use App\Filament\Resources\Users\RelationManagers\CalendarsRelationManager;
use App\Models\AddressBook;
use App\Models\AddressBookInstance;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\SharingService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 22: share management on the user page. The rows are whatever
 * SharingService reads; the panel does not write the share tables itself.
 */
class ShareAdminTest extends TestCase
{
    use RefreshDatabase;

    private const HELP = "Read and write lets the sharee change and delete the owner's items for everyone. A client may need the book set or added again after the access changes. Thunderbird keeps its own Read-only flag.";

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]));
    }

    public function test_admin_can_add_change_and_remove_calendar_sharees(): void
    {
        $this->assertShareManagement(true);
    }

    public function test_admin_can_add_change_and_remove_address_book_sharees(): void
    {
        $this->assertShareManagement(false);
    }

    public function test_the_user_choices_leave_out_the_owner_and_current_sharees(): void
    {
        $ada = $this->user('ada', 'Ada Lovelace');
        $bob = $this->user('bob', 'Bob Baker');
        $carol = $this->user('carol', 'Carol Clark');
        $dave = $this->user('dave', 'Zed Quarry', 'quarry@example.net');
        $calendar = $this->calendar($ada);
        $book = $this->book($ada);
        $sharing = app(SharingService::class);
        $sharing->share($calendar, $bob, SharingService::READ);
        $sharing->share($book, $bob, SharingService::READ);

        foreach ([
            [CalendarsRelationManager::class, $calendar],
            [AddressBooksRelationManager::class, $book],
        ] as [$manager, $record]) {
            Livewire::test($manager, [
                'ownerRecord' => $ada,
                'pageClass' => EditUser::class,
            ])
                ->mountTableAction('share', $record)
                ->assertMountedActionModalSee(self::HELP)
                ->assertSchemaComponentExists('sharee_id', checkComponentUsing: function (Select $select) use ($ada, $bob, $carol, $dave): bool {
                    $empty = $select->getSearchResults('');
                    $this->assertTrue($this->hasId($empty, (int) $carol->getKey()));
                    $this->assertTrue($this->hasId($empty, (int) $dave->getKey()));
                    $this->assertFalse($this->hasId($empty, (int) $ada->getKey()));
                    $this->assertFalse($this->hasId($empty, (int) $bob->getKey()));

                    $byName = $select->getSearchResults('Quarry');
                    $byEmail = $select->getSearchResults('quarry@example.net');
                    $byUsername = $select->getSearchResults('dave');
                    $this->assertTrue($this->hasId($byName, (int) $dave->getKey()));
                    $this->assertTrue($this->hasId($byEmail, (int) $dave->getKey()));
                    $this->assertTrue($this->hasId($byUsername, (int) $dave->getKey()));
                    $this->assertFalse($this->hasId($select->getSearchResults('Baker'), (int) $bob->getKey()));
                    $this->assertFalse($this->hasId($select->getSearchResults('ada@example.com'), (int) $ada->getKey()));
                    $this->assertTrue($this->hasId($select->getSearchResults('Clark'), (int) $carol->getKey()));
                    $this->assertTrue($this->hasId($select->getSearchResults('carol@'), (int) $carol->getKey()));

                    $label = (string) ($byUsername[(int) $dave->getKey()] ?? $byUsername[(string) $dave->getKey()] ?? '');
                    $this->assertStringContainsString('Zed Quarry', $label);
                    $this->assertStringContainsString('dave', $label);
                    $this->assertStringContainsString('quarry@example.net', $label);

                    return true;
                });

            foreach ([$ada, $bob] as $excluded) {
                Livewire::test($manager, [
                    'ownerRecord' => $ada,
                    'pageClass' => EditUser::class,
                ])
                    ->callTableAction('share', $record, data: [
                        'sharee_id' => $excluded->getKey(),
                        'share_access' => SharingService::READ_WRITE,
                    ])
                    ->assertHasFormErrors(['sharee_id']);
            }

            $sharees = $sharing->sharees($record);
            $this->assertCount(1, $sharees);
            $this->assertSame(SharingService::READ, (int) $sharees->first()?->getAttribute('access'));
            $this->assertSame('principals/bob', (string) $sharees->first()?->getAttribute('principaluri'));
        }
    }

    public function test_delete_confirmations_list_the_shares_that_go_away(): void
    {
        $admin = User::query()->where('is_admin', true)->firstOrFail();
        $ada = $this->user('ada', 'Ada Lovelace');
        $bob = $this->user('bob', 'Bob Baker');
        $carol = $this->user('carol', 'Carol Clark');
        $sharing = app(SharingService::class);
        $sharing->share($this->calendar($ada), $bob, SharingService::READ);
        $sharing->share($this->book($ada), $carol, SharingService::READ_WRITE);
        $sharing->share($this->book($bob), $ada, SharingService::READ);
        $sharing->share($this->calendar($carol), $ada, SharingService::READ_WRITE);

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->mountTableAction('delete', $this->calendar($ada))
            ->assertMountedActionModalSee('The calendar and its events, tasks, notes, and shares will be deleted.');

        Livewire::test(AddressBooksRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->mountTableAction('delete', $this->book($ada))
            ->assertMountedActionModalSee('The address book, its contacts, and its shares will be deleted.');

        $owned = 'Shares they own: Calendar "Calendar" — Bob Baker; Address book "Contacts" — Carol Clark.';
        $received = 'Shares they received: Calendar "Calendar" — Carol Clark; Address book "Contacts" — Bob Baker.';

        foreach ([
            Livewire::test(EditUser::class, ['record' => $ada->getKey()])->mountAction('delete'),
            Livewire::test(ListUsers::class)->mountTableAction('delete', $ada),
        ] as $page) {
            $page->assertMountedActionModalSee($owned)->assertMountedActionModalSee($received);
        }

        $erin = $this->user('erin', 'Erin Ellis');

        Livewire::test(EditUser::class, ['record' => $erin->getKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee('Shares they own: none. Shares they received: none.');

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee(LastActiveAdminException::CANNOT_DELETE)
            ->assertMountedActionModalDontSee('Shares they own:');
    }

    private function assertShareManagement(bool $calendar): void
    {
        $ada = $this->user('ada', 'Ada Lovelace');
        $bob = $this->user('bob', 'Bob Baker');
        $carol = $this->user('carol', 'Carol Clark');
        $record = $calendar ? $this->calendar($ada) : $this->book($ada);
        $manager = $calendar ? CalendarsRelationManager::class : AddressBooksRelationManager::class;
        $sharing = app(SharingService::class);
        $table = fn () => Livewire::test($manager, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ]);

        $table()
            ->assertTableColumnStateSet('sharees_count', '0', $record)
            ->callTableAction('share', $record, data: [
                'sharee_id' => $bob->getKey(),
                'share_access' => SharingService::READ,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Shares saved');

        $bobShare = $this->shareFor($record, $bob);
        $this->assertSame(SharingService::READ, (int) $bobShare->getAttribute('access'));
        $this->assertSame('principals/bob', (string) $bobShare->getAttribute('principaluri'));
        $table()->assertTableColumnStateSet('sharees_count', '1', $record);

        $table()
            ->callTableAction('share', $record, data: [
                'sharee_id' => $carol->getKey(),
                'share_access' => SharingService::READ_WRITE,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Shares saved');

        $this->assertSame($bobShare->getKey(), $bobShare->fresh()?->getKey());
        $this->assertSame(SharingService::READ, (int) $bobShare->fresh()?->getAttribute('access'));
        $carolShare = $this->shareFor($record, $carol);
        $this->assertSame(SharingService::READ_WRITE, (int) $carolShare->getAttribute('access'));
        $this->assertCount(2, $sharing->sharees($record));

        $page = $table()->mountTableAction('share', $record)
            ->assertMountedActionModalSee('Bob Baker')
            ->assertMountedActionModalSee('Carol Clark')
            ->assertMountedActionModalSee(self::HELP);
        $state = $page->instance()->mountedActions[0]['data'];

        foreach ($state['shares'] as $key => $item) {
            if ((int) $item['user_id'] === (int) $bob->getKey()) {
                $state['shares'][$key]['access'] = SharingService::READ_WRITE;
            }
        }

        $page->setTableActionData(['shares' => $state['shares']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Shares saved');

        $this->assertSame($bobShare->getKey(), $bobShare->fresh()?->getKey());
        $this->assertSame(SharingService::READ_WRITE, (int) $bobShare->fresh()?->getAttribute('access'));
        $this->assertSame(SharingService::READ_WRITE, (int) $carolShare->fresh()?->getAttribute('access'));
        $this->assertCount(2, $sharing->sharees($record));

        $page = $table()->mountTableAction('share', $record);
        $itemKey = null;

        foreach ($page->instance()->mountedActions[0]['data']['shares'] as $key => $item) {
            if ((int) $item['user_id'] === (int) $carol->getKey()) {
                $itemKey = $key;
            }
        }

        $this->assertNotNull($itemKey);

        $page->mountFormComponentAction('shares', 'delete', ['item' => $itemKey], 'mountedActionSchema0')
            ->assertMountedActionModalSee("This share will be removed. The owner's items stay.")
            ->callMountedFormComponentAction()
            ->assertNotified('Share removed');

        $this->assertNull($carolShare->fresh());
        $remaining = $sharing->sharees($record);
        $this->assertCount(1, $remaining);
        $this->assertSame((int) $bobShare->getKey(), (int) $remaining->first()?->getKey());
        $this->assertSame(SharingService::READ_WRITE, (int) $remaining->first()?->getAttribute('access'));
        $table()->assertTableColumnStateSet('sharees_count', '1', $record);

        if ($record instanceof CalendarInstance) {
            $this->assertSame(CalendarInstance::OWNER, (int) $record->fresh()?->getAttribute('access'));
            $this->assertSame(1, CalendarInstance::query()->where('calendarid', $record->calendarid)->where('access', CalendarInstance::OWNER)->count());
        } else {
            $this->assertSame(1, AddressBook::query()->where('principaluri', 'principals/ada')->count());
        }
    }

    private function user(string $username, string $displayName, ?string $email = null): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email ?? $username.'@example.com',
        ]);
    }

    private function calendar(User $user): CalendarInstance
    {
        return CalendarInstance::query()
            ->where('principaluri', $user->principal_uri)
            ->where('uri', 'default')
            ->where('access', CalendarInstance::OWNER)
            ->firstOrFail();
    }

    private function book(User $user): AddressBook
    {
        return AddressBook::query()
            ->where('principaluri', $user->principal_uri)
            ->where('uri', 'default')
            ->firstOrFail();
    }

    private function shareFor(CalendarInstance|AddressBook $record, User $sharee): CalendarInstance|AddressBookInstance
    {
        $share = app(SharingService::class)->sharees($record)->firstWhere('principaluri', $sharee->principal_uri);
        $this->assertNotNull($share);

        return $share;
    }

    /**
     * @param  array<int|string, mixed>  $options
     */
    private function hasId(array $options, int $id): bool
    {
        return array_key_exists($id, $options) || array_key_exists((string) $id, $options);
    }
}

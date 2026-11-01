<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dav\PrincipalBackend;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;
use Tests\TestCase;

class PrincipalBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookups_mailto_search_and_empty_groups(): void
    {
        $users = app(UserService::class);
        $users->create([
            'username' => 'ada',
            'display_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        $users->create([
            'username' => 'grace',
            'display_name' => 'Grace Hopper',
            'email' => 'grace@example.com',
        ]);

        $backend = new PrincipalBackend;

        $byPrefix = $backend->getPrincipalsByPrefix('principals');
        $this->assertSame(['principals/ada', 'principals/grace'], array_column($byPrefix, 'uri'));
        $this->assertSame('Ada Lovelace', $byPrefix[0]['{DAV:}displayname']);
        $this->assertSame('ada@example.com', $byPrefix[0]['{http://sabredav.org/ns}email-address']);
        $this->assertSame([], $backend->getPrincipalsByPrefix('groups'));

        $ada = $backend->getPrincipalByPath('principals/ada');
        $this->assertIsArray($ada);
        $this->assertSame('principals/ada', $ada['uri']);
        $this->assertNull($backend->getPrincipalByPath('principals/missing'));

        $this->assertSame('principals/ada', $backend->findByUri('mailto:Ada@Example.com', 'principals'));
        $this->assertSame('principals/grace', $backend->findByUri('principals/grace', 'principals'));
        $this->assertNull($backend->findByUri('mailto:missing@example.com', 'principals'));
        $this->assertNull($backend->findByUri('mailto:', 'principals'));

        $this->assertSame(
            ['principals/ada'],
            $backend->searchPrincipals('principals', ['{DAV:}displayname' => 'ada']),
        );
        $this->assertSame(
            ['principals/ada', 'principals/grace'],
            $backend->searchPrincipals('principals', [
                '{DAV:}displayname' => 'ada',
                '{http://sabredav.org/ns}email-address' => 'grace',
            ], 'anyof'),
        );
        $this->assertSame(
            [],
            $backend->searchPrincipals('principals', [
                '{DAV:}displayname' => 'ada',
                '{http://sabredav.org/ns}email-address' => 'grace',
            ], 'allof'),
        );
        $this->assertSame(
            [],
            $backend->searchPrincipals('principals', ['{DAV:}unsupported' => 'ada']),
        );
        $this->assertSame([], $backend->searchPrincipals('principals', []));

        $this->assertSame([], $backend->getGroupMemberSet('principals/ada'));
        $this->assertSame([], $backend->getGroupMembership('principals/ada'));

        $this->expectException(Forbidden::class);
        $backend->setGroupMemberSet('principals/ada', ['principals/grace']);
    }

    public function test_display_name_updates_the_user_and_email_is_refused(): void
    {
        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        $backend = new PrincipalBackend;

        $displayName = new PropPatch([
            '{DAV:}displayname' => 'Augusta Ada',
        ]);
        $backend->updatePrincipal('principals/ada', $displayName);

        $this->assertTrue($displayName->commit());
        $fresh = $user->fresh();
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertSame('Augusta Ada', $fresh->display_name);
        $this->assertSame('Augusta Ada', $fresh->principal?->display_name);
        $this->assertSame('ada@example.com', $fresh->email);

        $email = new PropPatch([
            '{http://sabredav.org/ns}email-address' => 'other@example.com',
        ]);
        $backend->updatePrincipal('principals/ada', $email);

        $this->assertFalse($email->commit());
        $this->assertSame(403, $email->getResult()['{http://sabredav.org/ns}email-address']);
        $this->assertSame('ada@example.com', $user->fresh()?->email);
        $this->assertSame('ada@example.com', $user->fresh()?->principal?->email);
    }

    public function test_a_missing_principal_has_no_group_membership(): void
    {
        $backend = new PrincipalBackend;

        $this->expectException(Exception::class);

        $backend->getGroupMemberSet('principals/missing');
    }
}

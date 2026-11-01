<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Pages\Diagnostics;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\CountsWidget;
use App\Filament\Widgets\Diagnostics\AppPasswordsTable;
use App\Filament\Widgets\Diagnostics\DavErrorsTable;
use App\Filament\Widgets\Diagnostics\FailedLoginsTable;
use App\Filament\Widgets\ServerStatusWidget;
use App\Filament\Widgets\ServicesWidget;
use App\Models\AppPassword;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\DiagnosticsSummary;
use App\Services\ServerCounts;
use App\Services\SettingsService;
use App\Services\UserService;
use App\Support\DatabaseHealth;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 6: the Dashboard and Diagnostics pages.
 */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['nexusdav.env_set' => []]);
    }

    public function test_only_active_admins_can_open_the_panel_pages(): void
    {
        $pages = ['/admin', '/admin/settings', '/admin/configuration', '/admin/diagnostics'];

        foreach ($pages as $page) {
            $this->get($page)->assertRedirect('/admin/login');
        }

        $this->actingAs($this->makeUser('ada'));

        foreach ($pages as $page) {
            $this->get($page)->assertForbidden();
        }

        $this->actingAs($this->makeUser('root', true));

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_the_dashboard_shows_the_four_cards_and_none_of_filaments_defaults(): void
    {
        $this->actingAs($this->makeUser('root', true));

        $page = $this->get('/admin');

        $page->assertOk();
        $page->assertSee('Server status');
        $page->assertSee('Services');
        $page->assertSee('Needs attention');
        $page->assertSee('App passwords');
        $page->assertSee('Manage in Settings');
        $page->assertDontSee('fi-filament-info-widget');
        $page->assertDontSee('fi-account-widget');
        $page->assertDontSee('filamentphp.com');
    }

    public function test_server_status_and_services(): void
    {
        config(['nexusdav.version' => '0.1.0+abc1234']);
        $this->actingAs($this->makeUser('root', true));

        Livewire::test(ServerStatusWidget::class)
            ->assertSee('OK ('.app(DatabaseHealth::class)->label().')', false)
            ->assertSee('NexusDAV 0.1.0+abc1234');

        app(SettingsService::class)->save(['notes' => false]);
        config(['nexusdav.env_set' => ['NEXUSDAV_TASKS']]);

        Livewire::test(ServicesWidget::class)
            ->assertSee('CalDAV')
            ->assertSee('Off')
            ->assertSee('Set by environment');
    }

    public function test_a_failing_database_check_shows_only_unavailable(): void
    {
        $this->actingAs($this->makeUser('root', true));
        $this->app->instance(DatabaseHealth::class, new class extends DatabaseHealth
        {
            public function isUp(): bool
            {
                return false;
            }
        });

        Livewire::test(ServerStatusWidget::class)
            ->assertSee('Unavailable')
            ->assertDontSee('SQLite')
            ->assertDontSee('PostgreSQL')
            ->assertDontSee(':memory:')
            ->assertDontSee('SQLSTATE');

        Livewire::test(AttentionWidget::class)->assertSee('The database is unavailable');
        $this->get('/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_counts_match_the_data_and_hide_switched_off_services(): void
    {
        $root = $this->makeUser('root', true);
        $ada = $this->makeUser('ada');
        $bob = $this->makeUser('bob');
        $bob->forceFill(['is_active' => false])->save();
        $token = $this->token($ada);
        $revoked = app(AppPasswordService::class)->issue($bob, 'old')['app_password'];
        app(AppPasswordService::class)->revoke($revoked);

        foreach (['e1' => 'VEVENT', 'e2' => 'VEVENT', 't1' => 'VTODO', 'n1' => 'VJOURNAL'] as $uid => $component) {
            $this->dav('PUT', '/dav/calendars/ada/default/'.$uid.'.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->item($uid, $component))->assertCreated();
        }

        $this->dav('PUT', '/dav/addressbooks/ada/default/c1.vcf', 'ada', $token, ['CONTENT_TYPE' => 'text/vcard'], $this->card('c1'))->assertCreated();
        app(ServerCounts::class)->forget();
        $this->actingAs($root);

        $counts = app(ServerCounts::class)->all();
        $this->assertSame(3, $counts['users']);
        $this->assertSame(1, $counts['disabled_users']);
        $this->assertSame(3, $counts['calendars']);
        $this->assertSame(1, $counts['app_passwords']);
        $this->assertSame(2, $counts['events']);
        $this->assertSame(1, $counts['tasks']);
        $this->assertSame(1, $counts['notes']);
        $this->assertSame(1, $counts['contacts']);

        Livewire::test(CountsWidget::class)
            ->assertSee('1 disabled')
            ->assertSee('Events and tasks')
            ->assertSee('Notes')
            ->assertSee('Contacts');

        app(SettingsService::class)->save(['notes' => false, 'tasks' => false, 'carddav' => false]);

        Livewire::test(CountsWidget::class)
            ->assertDontSee('Events and tasks')
            ->assertSee('Events')
            ->assertDontSee('Notes')
            ->assertDontSee('Contacts')
            ->assertDontSee('Address books');
    }

    public function test_needs_attention_is_quiet_then_matches_diagnostics(): void
    {
        $root = $this->makeUser('root', true);
        $ada = $this->makeUser('ada');
        [$publicId] = explode('.', $this->token($ada), 2);
        $this->actingAs($root);

        Livewire::test(AttentionWidget::class)->assertSee('Nothing needs attention');

        $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.wrong-secret')->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/calendars/ada/missing/', 'ada', $this->token($ada))->assertNotFound();
        $this->failAdminLogin('root@example.com');
        $this->actingAs($root);

        $summary = app(DiagnosticsSummary::class);
        $this->assertSame(2, $summary->failedLogins());
        $this->assertSame(2, $summary->davErrors());

        Livewire::test(AttentionWidget::class)
            ->assertSee('2 failed logins in the last 24 hours')
            ->assertSee('2 DAV errors in the last 24 hours')
            ->assertDontSee('Nothing needs attention');

        $this->get('/admin/diagnostics')->assertSee('Last 24 hours: 2 failed logins, 2 DAV errors.');
    }

    public function test_diagnostics_lists_failed_logins_dav_errors_and_every_app_password(): void
    {
        $root = $this->makeUser('root', true);
        $ada = $this->makeUser('ada');
        $bob = $this->makeUser('bob');
        $adaToken = $this->token($ada);
        [$publicId] = explode('.', $adaToken, 2);
        app(AppPasswordService::class)->issue($bob, 'laptop');

        $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.wrong-secret')->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/calendars/ada/missing/', 'ada', $adaToken)->assertNotFound();
        $this->failAdminLogin('root@example.com');
        $this->actingAs($root);

        $davLogin = DiagnosticEvent::query()->where('source', DiagnosticEvent::SOURCE_DAV)->where('kind', DiagnosticEvent::KIND_AUTH_FAILED)->firstOrFail();
        $adminLogin = DiagnosticEvent::query()->where('source', DiagnosticEvent::SOURCE_ADMIN)->firstOrFail();
        $notFound = DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->firstOrFail();
        $this->assertSame(404, $notFound->status);
        $this->assertSame('/dav/calendars/ada/missing', $notFound->path);

        Livewire::test(FailedLoginsTable::class)
            ->assertCanSeeTableRecords([$davLogin, $adminLogin])
            ->assertSee('bad secret')
            ->filterTable('source', DiagnosticEvent::SOURCE_ADMIN)
            ->assertCanSeeTableRecords([$adminLogin])
            ->assertCanNotSeeTableRecords([$davLogin]);

        Livewire::test(DavErrorsTable::class)
            ->assertCanSeeTableRecords([$davLogin, $notFound])
            ->assertCanNotSeeTableRecords([$adminLogin])
            ->assertSee('bad secret')
            ->assertSee('Not Found');

        $all = AppPassword::query()->get();
        $this->assertCount(2, $all);
        $laptop = $all->firstWhere('name', 'laptop');
        $this->assertInstanceOf(AppPassword::class, $laptop);

        Livewire::test(AppPasswordsTable::class)
            ->assertCanSeeTableRecords($all)
            ->assertSee('bob')
            ->callTableAction('revoke', $laptop)
            ->assertNotified('App password revoked');

        $this->assertNotNull($laptop->fresh()?->revoked_at);
    }

    public function test_a_flood_of_dav_errors_from_one_address_is_capped(): void
    {
        config(['nexusdav.diagnostics.max_rows_per_address' => 3]);
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);

        foreach (range(1, 8) as $i) {
            $this->dav('PROPFIND', '/dav/calendars/ada/missing-'.$i.'/', 'ada', $token)->assertNotFound();
        }

        $rows = DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->get();
        $this->assertCount(4, $rows);
        $summary = $rows->firstWhere('reason', DiagnosticEvent::REASON_MANY_REQUESTS);
        $this->assertInstanceOf(DiagnosticEvent::class, $summary);
        $this->assertSame(5, $summary->repeat_count);
        $this->assertSame(8, app(DiagnosticsSummary::class)->davErrors());
    }

    private function makeUser(string $username, bool $admin = false): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.com',
            'is_admin' => $admin,
            'password' => 'correct-horse-battery',
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    private function failAdminLogin(string $email): void
    {
        auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['email' => $email, 'password' => 'not-the-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $uri, string $username, string $password, array $server = [], ?string $body = null): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password),
            'HTTP_DEPTH' => '0',
            'CONTENT_TYPE' => 'application/xml',
            ...$server,
        ], $body);
    }

    private function item(string $uid, string $component): string
    {
        $date = $component === 'VTODO' ? 'DUE:20261102T130000Z' : 'DTSTART:20261102T130000Z';

        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NexusDAV//EN',
            'BEGIN:'.$component, 'UID:'.$uid.'@nexusdav.test', 'DTSTAMP:20261101T120000Z',
            $date, 'SUMMARY:Item', 'END:'.$component, 'END:VCALENDAR', '',
        ]);
    }

    private function card(string $uid): string
    {
        return implode("\r\n", ['BEGIN:VCARD', 'VERSION:3.0', 'UID:'.$uid, 'FN:Card', 'N:Card;;;;', 'END:VCARD', '']);
    }
}

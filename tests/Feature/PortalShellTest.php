<?php

namespace Tests\Feature;

use App\Filament\Portal\Calendar;
use App\Filament\Portal\Contacts;
use App\Filament\Portal\Dashboard;
use App\Filament\Portal\Files;
use App\Filament\Portal\Notes;
use App\Filament\Portal\Tasks;
use App\Models\User;
use App\Portal\PortalApps;
use App\Portal\PortalPage;
use App\Portal\PortalState;
use App\Portal\PortalTime;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PortalTestApp;
use Tests\Support\PortalTestCard;
use Tests\Support\PortalTestPage;
use Tests\TestCase;

/**
 * PR 49 of the Version 2 plan: the app registry, the navigation built from
 * it, page access, Dashboard cards, PortalTime and PortalState.
 */
class PortalShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.env_set' => []]);
        app(SettingsService::class)->save(['portal' => true, 'files' => true]);
    }

    public function test_the_registry_lists_the_apps_in_order_with_their_switches(): void
    {
        $apps = app(PortalApps::class)->all();

        $this->assertSame(['dashboard', 'calendar', 'tasks', 'notes', 'contacts', 'files'], array_map(fn ($app) => $app->id(), $apps));
        $this->assertSame(
            [[], ['caldav'], ['caldav', 'tasks'], ['caldav', 'notes'], ['carddav'], ['files']],
            array_map(fn ($app) => $app->switches(), $apps),
        );
        $this->assertSame([Dashboard::class, Calendar::class, Tasks::class, Notes::class, Contacts::class, Files::class], app(PortalApps::class)->pages());
    }

    public function test_the_navigation_shows_every_visible_app_in_order(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Dashboard', 'Calendar', 'Tasks', 'Notes', 'Contacts', 'Files'])
            ->assertSee('fi-theme-switcher', false);

        foreach (['/calendar', '/tasks', '/notes', '/contacts', '/files'] as $page) {
            $this->get($page)->assertOk()->assertSee('This part of the portal is not ready yet.');
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function switches(): array
    {
        return [
            'CalDAV hides Calendar' => ['caldav', '/calendar', 'Calendar'],
            'CalDAV hides Tasks' => ['caldav', '/tasks', 'Tasks'],
            'CalDAV hides Notes' => ['caldav', '/notes', 'Notes'],
            'Tasks hides Tasks' => ['tasks', '/tasks', 'Tasks'],
            'Notes hides Notes' => ['notes', '/notes', 'Notes'],
            'CardDAV hides Contacts' => ['carddav', '/contacts', 'Contacts'],
            'Files hides Files' => ['files', '/files', 'Files'],
        ];
    }

    #[DataProvider('switches')]
    public function test_an_app_is_hidden_and_answers_404_while_a_switch_is_off(string $switch, string $path, string $label): void
    {
        $this->actingAs(User::factory()->create());
        app(SettingsService::class)->save([$switch => false]);

        $this->get($path)->assertNotFound();

        $navigation = $this->navigationLabels();
        $this->assertNotContains($label, $navigation);
        $this->assertContains('Dashboard', $navigation);
    }

    public function test_tasks_off_leaves_calendar_and_notes(): void
    {
        $this->actingAs(User::factory()->create());
        app(SettingsService::class)->save(['tasks' => false]);

        $this->get('/calendar')->assertOk();
        $this->get('/notes')->assertOk();
        $this->assertSame(['Dashboard', 'Calendar', 'Notes', 'Contacts', 'Files'], $this->navigationLabels());
    }

    public function test_a_new_app_needs_only_its_registry_class_and_its_pages(): void
    {
        app(PortalApps::class)->register(PortalTestApp::class);
        $this->registerPageRoutes(PortalTestPage::class);
        $this->actingAs(User::factory()->create());

        $this->assertSame(['Dashboard', 'Calendar', 'Tasks', 'Test app', 'Notes', 'Contacts', 'Files'], $this->navigationLabels());
        $this->get('/test-app')->assertOk()->assertSee('Test app page');
        // Cards load lazily: the Dashboard holds the card's component.
        $this->get('/')->assertOk()->assertSee('tests.support.portal-test-card');
        Livewire::test(PortalTestCard::class)->assertSee('Test card')->assertSee('Test card body')->assertSee('/test-app');
        $this->assertSame([PortalTestCard::class], app(PortalApps::class)->cards());

    }

    public function test_a_new_app_follows_its_switches(): void
    {
        app(SettingsService::class)->save(['files' => false]);
        app(PortalApps::class)->register(PortalTestApp::class);
        $this->registerPageRoutes(PortalTestPage::class);
        $this->actingAs(User::factory()->create());

        $this->get('/test-app')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('tests.support.portal-test-card');
        $this->assertNotContains('Test app', $this->navigationLabels());
        $this->assertFalse(PortalTestCard::canView());
        $this->assertSame([], app(PortalApps::class)->cards());
    }

    public function test_the_dashboard_shows_the_welcome_line_without_cards(): void
    {
        $this->actingAs(User::factory()->create(['display_name' => 'Ada']));

        $this->assertSame([], app(PortalApps::class)->cards());
        $this->get('/')->assertOk()->assertSee('Welcome, Ada.');
    }

    public function test_portal_times_follow_the_user_zone_the_time_format_and_the_week_start(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->actingAs($user);
        $moment = CarbonImmutable::parse('2026-12-31 20:30:00', 'UTC');

        $this->assertSame('Asia/Tokyo', PortalTime::timezone());
        $this->assertSame('Jan 1, 2027', PortalTime::date($moment));
        $this->assertSame('05:30', PortalTime::time($moment));

        config(['nexusdav.time_format' => '12']);
        $this->assertSame('Jan 1, 2027 5:30 AM', PortalTime::dateTime($moment));

        // Friday, January 1, 2027 in Tokyo; ISO week 53 of 2026.
        $this->assertSame(53, PortalTime::weekNumber($moment));
        config(['nexusdav.week_start' => 1]);
        $this->assertSame('2026-12-28', PortalTime::startOfWeek($moment)->toDateString());
        config(['nexusdav.week_start' => 0]);
        $this->assertSame('2026-12-27', PortalTime::startOfWeek($moment)->toDateString());
        config(['nexusdav.week_start' => 6]);
        $this->assertSame('2026-12-26', PortalTime::startOfWeek($moment)->toDateString());

        app()->setLocale('ru');
        $this->assertSame('1 янв 2027 г.', PortalTime::date($moment));

        $user->forceFill(['timezone' => null])->save();
        config(['nexusdav.default_timezone' => 'Europe/Berlin']);
        $this->assertSame('Europe/Berlin', PortalTime::timezone());
        $this->assertFalse(PortalTime::showWeekNumbers());
    }

    public function test_portal_state_keeps_keys_per_user(): void
    {
        $state = app(PortalState::class);
        $ada = User::factory()->create();
        $bob = User::factory()->create();

        $this->assertNull($state->get($ada, 'calendar.view'));
        $this->assertSame('week', $state->get($ada, 'calendar.view', 'week'));

        $state->put($ada, 'calendar.view', 'month');
        $state->put($ada, 'calendar.hidden', [3, 5]);

        $this->assertSame('month', $state->get($ada->fresh(), 'calendar.view'));
        $this->assertSame([3, 5], $state->get($ada->fresh(), 'calendar.hidden'));
        $this->assertSame('month', $state->get($ada, 'calendar.view'));
        $this->assertSame([], $state->all($bob->fresh()));

        $state->forget($ada, 'calendar.view');
        $this->assertSame(['calendar.hidden' => [3, 5]], $state->all($ada->fresh()));
        $this->assertNull($bob->fresh()?->portal_state);
    }

    public function test_portal_state_refuses_bad_keys_and_oversized_state(): void
    {
        $state = app(PortalState::class);
        $user = User::factory()->create();

        foreach (['', 'Calendar.View', 'calendar..view', 'calendar view', str_repeat('a', 65)] as $key) {
            try {
                $state->put($user, $key, 1);
                $this->fail('The key '.$key.' was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $state->all($user->fresh()));
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $state->put($user, 'notes.draft', str_repeat('x', PortalState::MAX_BYTES));
    }

    public function test_two_copies_of_a_user_keep_each_others_portal_state_keys(): void
    {
        $state = app(PortalState::class);
        $ada = User::factory()->create();
        $first = User::query()->findOrFail($ada->getKey());
        $second = User::query()->findOrFail($ada->getKey());

        $state->put($first, 'calendar.view', 'week');
        $state->put($second, 'calendar.hidden', [1]);

        $this->assertSame([
            'calendar.view' => 'week',
            'calendar.hidden' => [1],
        ], $state->all($ada->fresh()));
        $this->assertSame('week', $state->get($second, 'calendar.view'));
        $this->assertSame([1], $state->get($first->fresh(), 'calendar.hidden'));
    }

    public function test_a_stale_copy_forgets_one_portal_state_key_and_keeps_the_other(): void
    {
        $state = app(PortalState::class);
        $ada = User::factory()->create();
        $writer = User::query()->findOrFail($ada->getKey());
        $stale = User::query()->findOrFail($ada->getKey());

        $state->put($writer, 'calendar.view', 'week');
        $state->put($writer, 'calendar.hidden', [1]);

        // The key is not in this copy's memory. Forgetting a key the document
        // does not have leaves the stored keys, and forgetting one removes
        // only that key.
        $state->forget($stale, 'notes.draft');
        $this->assertSame([
            'calendar.view' => 'week',
            'calendar.hidden' => [1],
        ], $state->all($ada->fresh()));

        $state->forget($stale, 'calendar.view');
        $this->assertSame(['calendar.hidden' => [1]], $state->all($ada->fresh()));
        $this->assertSame([1], $state->get($stale, 'calendar.hidden'));
    }

    /**
     * The labels of the portal's top navigation, as the Dashboard shows it
     * to the signed-in user.
     *
     * @return list<string>
     */
    private function navigationLabels(): array
    {
        $html = (string) $this->get('/')->assertOk()->getContent();
        preg_match_all('/class="fi-topbar-item-label"[^>]*>\s*([^<]+?)\s*</', $html, $matches);

        return $matches[1];
    }

    /**
     * What Filament does for every page of the panel at boot, for a page
     * added afterwards.
     *
     * @param  class-string<PortalPage>  $page
     */
    private function registerPageRoutes(string $page): void
    {
        $panel = Filament::getPanel('portal');
        $panel->pages([$page]);

        Route::middleware($panel->getMiddleware())
            ->name('filament.portal.')
            ->group(function () use ($panel, $page): void {
                Route::middleware($panel->getAuthMiddleware())->group(fn () => $page::registerRoutes($panel));
            });

        Route::getRoutes()->refreshNameLookups();
    }
}

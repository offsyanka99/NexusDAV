<?php

namespace Tests\Feature;

use App\Filament\Pages\Configuration;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\AddressBooksRelationManager;
use App\Filament\Resources\Users\RelationManagers\AppPasswordsRelationManager;
use App\Filament\Resources\Users\RelationManagers\CalendarsRelationManager;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\CountsWidget;
use App\Filament\Widgets\Diagnostics\AppPasswordsTable;
use App\Filament\Widgets\Diagnostics\DavErrorsTable;
use App\Filament\Widgets\Diagnostics\FailedLoginsTable;
use App\Filament\Widgets\ServerStatusWidget;
use App\Filament\Widgets\ServicesWidget;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\SettingsService;
use App\Services\UserService;
use App\Support\LocaleCatalog;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Generated values that the pseudo-locale check treats as user data.
     *
     * @var list<string>
     */
    private array $sampleData = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['nexusdav.env_set' => []]);
    }

    public function test_locale_catalog_and_server_default_setting(): void
    {
        $catalog = app(LocaleCatalog::class);

        $this->assertSame(['en' => 'English', 'ru' => 'Русский'], $catalog->all());
        $this->assertTrue($catalog->supports('ru'));
        $this->assertFalse($catalog->supports('fr'));

        app(SettingsService::class)->save(['default_locale' => 'ru']);

        $this->assertSame('ru', config('nexusdav.default_locale'));
        $this->assertSame('NEXUSDAV_LOCALE', app(SettingsService::class)->envName('default_locale'));

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);
        config(['nexusdav.env_set' => ['NEXUSDAV_LOCALE']]);
        $this->assertTrue(app(SettingsService::class)->isLocked('default_locale'));
        Livewire::test(Configuration::class)->assertSee('Set by NEXUSDAV_LOCALE');
    }

    public function test_user_and_server_locale_are_applied_only_to_the_admin_panel(): void
    {
        config(['nexusdav.default_locale' => 'ru']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        $this->get('/admin/users')
            ->assertOk()
            ->assertSee('Пользователи');
        $this->assertSame('en', App::getLocale());

        $admin->forceFill(['locale' => 'en'])->save();
        $this->get('/admin/users')
            ->assertOk()
            ->assertSee('Users');
        $this->assertSame('en', App::getLocale());

        config(['nexusdav.default_locale' => 'ru']);
        $admin->forceFill(['locale' => 'fr'])->save();
        $this->get('/admin/users')->assertOk()->assertSee('Пользователи');
        $this->assertSame('en', App::getLocale());

        $this->get('/health')->assertExactJson(['status' => 'ok']);
    }

    public function test_login_uses_the_server_default_language(): void
    {
        config(['nexusdav.default_locale' => 'ru']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Электронная почта');

        $this->assertSame('en', App::getLocale());
    }

    public function test_dashboard_and_user_pages_render_in_russian(): void
    {
        config(['nexusdav.default_locale' => 'ru']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $user = User::factory()->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->actingAs($admin);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Состояние сервера')
            ->assertSee('Службы')
            ->assertSee('Требует внимания')
            ->assertSee('Язык');

        $this->get('/admin/users')
            ->assertOk()
            ->assertSee('Пользователи')
            ->assertSee('Имя пользователя')
            ->assertSee('Отображаемое имя');

        $this->get('/admin/users/'.$user->getKey().'/edit')
            ->assertOk()
            ->assertSee('Изменить пользователя')
            ->assertSee('Информация о пользователе')
            ->assertSee('Календари')
            ->assertSee('Адресные книги')
            ->assertSee('Пароли приложений');
    }

    public function test_diagnostics_localizes_known_reasons_without_changing_stored_values(): void
    {
        App::setLocale('ru');
        $event = DiagnosticEvent::query()->create([
            'occurred_at' => now(),
            'source' => DiagnosticEvent::SOURCE_DAV,
            'kind' => DiagnosticEvent::KIND_AUTH_FAILED,
            'ip' => '127.0.0.1',
            'username' => 'ada',
            'public_id' => 'app-id',
            'method' => 'PROPFIND',
            'path' => '/dav/',
            'status' => 401,
            'reason' => 'bad secret',
            'repeat_count' => 1,
            'bucket' => intdiv(now()->getTimestamp(), 60),
        ]);

        Livewire::test(DavErrorsTable::class)->assertSee('неверный секрет');
        $this->assertSame('bad secret', $event->fresh()?->reason);
        $this->get('/health')->assertExactJson(['status' => 'ok']);
    }

    public function test_translation_catalogs_match_all_literal_source_keys(): void
    {
        $used = [];

        foreach ([app_path(), resource_path('views')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = $file->getContents();
                preg_match_all('/\b(?:__|trans_choice|trans)\s*\(\s*([\'"])(.*?)\1/s', $source, $matches);

                foreach ($matches[2] as $key) {
                    $used[$key] = true;
                }

                preg_match_all('/@lang\s*\(\s*([\'"])(.*?)\1/s', $source, $matches);

                foreach ($matches[2] as $key) {
                    $used[$key] = true;
                }
            }
        }

        $english = json_decode(File::get(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $russian = json_decode(File::get(lang_path('ru.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($english['_language_name'], $russian['_language_name']);

        $expected = array_keys($used);
        $englishKeys = array_keys($english);
        $russianKeys = array_keys($russian);
        sort($expected);
        sort($englishKeys);
        sort($russianKeys);

        $this->assertSame($expected, $englishKeys, 'English translations must be complete and contain no unused keys.');
        $this->assertSame($expected, $russianKeys, 'Russian translations must be complete and contain no unused keys.');
    }

    public function test_russian_framework_messages_cover_published_english_groups(): void
    {
        foreach (['auth', 'pagination', 'passwords', 'validation'] as $group) {
            $english = array_keys(Arr::dot(require lang_path('en/'.$group.'.php')));
            $english = array_values(array_filter($english, fn (string $key): bool => $key !== 'attributes'));
            $russian = array_keys(Arr::dot(require lang_path('ru/'.$group.'.php')));
            $russian = array_values(array_filter($russian, fn (string $key): bool => ! str_starts_with($key, 'attributes.')));
            sort($english);
            sort($russian);

            $this->assertSame($english, $russian, "Russian {$group} messages must cover the published English keys.");
        }
    }

    public function test_russian_dashboard_plural_forms_cover_one_two_five_and_twenty_one(): void
    {
        $cases = [
            [':count failed login|:count failed logins', 1, '1 неудачный вход'],
            [':count failed login|:count failed logins', 2, '2 неудачных входа'],
            [':count failed login|:count failed logins', 5, '5 неудачных входов'],
            [':count failed login|:count failed logins', 21, '21 неудачный вход'],
            [':count DAV error|:count DAV errors', 1, '1 ошибка DAV'],
            [':count DAV error|:count DAV errors', 2, '2 ошибки DAV'],
            [':count DAV error|:count DAV errors', 5, '5 ошибок DAV'],
            [':count DAV error|:count DAV errors', 21, '21 ошибка DAV'],
            [':count failed login in the last 24 hours|:count failed logins in the last 24 hours', 1, '1 неудачный вход за последние 24 часа'],
            [':count failed login in the last 24 hours|:count failed logins in the last 24 hours', 2, '2 неудачных входа за последние 24 часа'],
            [':count failed login in the last 24 hours|:count failed logins in the last 24 hours', 5, '5 неудачных входов за последние 24 часа'],
            [':count failed login in the last 24 hours|:count failed logins in the last 24 hours', 21, '21 неудачный вход за последние 24 часа'],
            [':count DAV error in the last 24 hours|:count DAV errors in the last 24 hours', 1, '1 ошибка DAV за последние 24 часа'],
            [':count DAV error in the last 24 hours|:count DAV errors in the last 24 hours', 2, '2 ошибки DAV за последние 24 часа'],
            [':count DAV error in the last 24 hours|:count DAV errors in the last 24 hours', 5, '5 ошибок DAV за последние 24 часа'],
            [':count DAV error in the last 24 hours|:count DAV errors in the last 24 hours', 21, '21 ошибка DAV за последние 24 часа'],
        ];

        foreach ($cases as [$key, $count, $expected]) {
            $this->assertSame($expected, trans_choice($key, $count, ['count' => $count], 'ru'));
        }
    }

    public function test_user_and_configuration_validation_messages_are_russian(): void
    {
        App::setLocale('ru');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => '',
                'display_name' => '',
                'email' => 'invalid',
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'required', 'email' => 'email'])
            ->assertSee('Поле имя пользователя обязательно.')
            ->assertSee('Поле электронная почта должно содержать корректный адрес электронной почты.');

        Livewire::test(Configuration::class)
            ->fillForm(['session_lifetime' => 4])
            ->call('save')
            ->assertHasFormErrors(['session_lifetime' => 'min'])
            ->assertSee('Значение поля время жизни сеанса должно быть не меньше 5.');
    }

    public function test_saving_a_new_server_default_language_reloads_the_page_when_it_applies(): void
    {
        config(['nexusdav.default_locale' => 'en']);
        $follower = User::factory()->create(['is_admin' => true, 'is_active' => true, 'locale' => null]);
        $this->actingAs($follower);

        Livewire::test(Configuration::class)
            ->fillForm(['default_locale' => 'ru'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Сохранено')
            ->assertRedirect(Configuration::getUrl());

        $this->assertSame('ru', app(SettingsService::class)->get('default_locale'));

        Livewire::test(Configuration::class)
            ->fillForm(['session_lifetime' => 60])
            ->call('save')
            ->assertNoRedirect();

        $own = User::factory()->create(['is_admin' => true, 'is_active' => true, 'locale' => 'en']);
        $this->actingAs($own);
        // Livewire::test() skips the panel middleware that sets this per request.
        App::setLocale($own->resolvedLocale());

        Livewire::test(Configuration::class)
            ->fillForm(['default_locale' => 'en'])
            ->call('save')
            ->assertNotified('Saved')
            ->assertNoRedirect();
    }

    public function test_an_admin_with_their_own_language_is_told_it_stays_when_the_server_default_changes(): void
    {
        config(['nexusdav.default_locale' => 'en']);
        $own = User::factory()->create(['is_admin' => true, 'is_active' => true, 'locale' => 'en']);
        $this->actingAs($own);
        App::setLocale('en');

        Livewire::test(Configuration::class)
            ->fillForm(['default_locale' => 'ru'])
            ->call('save')
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Saved')
                    ->body('The change applies from the next request. No restart is needed. Your own language, English, stays. Change it in the user menu.'),
            )
            ->assertNoRedirect();

        // Choosing the language they already follow needs no notice.
        $follower = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($follower);
        App::setLocale($follower->resolvedLocale());

        Livewire::test(Configuration::class)
            ->fillForm(['default_locale' => 'en'])
            ->call('save')
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Saved')
                    ->body('The change applies from the next request. No restart is needed.'),
            );
    }

    public function test_the_user_menu_says_which_language_is_shown_and_why(): void
    {
        config(['nexusdav.default_locale' => 'ru']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);
        App::setLocale($admin->resolvedLocale());

        $this->get('/admin')->assertOk()->assertSee('Язык: По умолчанию на сервере (Русский)');

        $admin->forceFill(['locale' => 'en'])->save();
        App::setLocale('en');

        $this->get('/admin')->assertOk()->assertSee('Language: English');
    }

    public function test_user_menu_can_save_and_clear_an_individual_language(): void
    {
        config(['nexusdav.default_locale' => 'en']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        $this->get('/admin')->assertOk()->assertSee('Language');

        Livewire::test(Topbar::class)
            ->callAction('language', data: ['locale' => 'ru'])
            ->assertRedirect(url('/admin'));

        $this->assertSame('ru', $admin->fresh()?->locale);
        $this->get('/admin/users')->assertOk()->assertSee('Пользователи');

        Livewire::test(Topbar::class)
            ->callAction('language', data: ['locale' => null])
            ->assertRedirect(url('/admin'));

        $this->assertNull($admin->fresh()?->locale);
        $this->get('/admin/users')->assertOk()->assertSee('Users');

        Livewire::test(Topbar::class)
            ->callAction('language', data: ['locale' => 'fr'])
            ->assertHasActionErrors(['locale']);

        $this->assertNull($admin->fresh()?->locale);
    }

    public function test_a_broken_language_file_is_skipped_instead_of_breaking_requests(): void
    {
        $directory = storage_path('framework/testing/broken-lang');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/en.json', '{"_language_name": "English"}');
        File::put($directory.'/xx.json', '{not json');
        File::put($directory.'/yy.json', '{"Users": "Yy"}');
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($directory));

        $this->assertSame(['en' => 'English'], (new LocaleCatalog($directory))->all());
        $this->assertCount(count(File::glob(lang_path('*.json'))), app(LocaleCatalog::class)->all(), 'Every shipped lang/*.json needs a _language_name.');
    }

    public function test_failed_logins_and_dav_errors_show_translated_reasons(): void
    {
        App::setLocale('ru');
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));
        $this->diagnosticEvent(DiagnosticEvent::KIND_AUTH_FAILED, DiagnosticEvent::SOURCE_ADMIN, 422, 'bad password');
        $this->diagnosticEvent(DiagnosticEvent::KIND_AUTH_FAILED, DiagnosticEvent::SOURCE_DAV, 401, 'revoked');
        $this->diagnosticEvent(DiagnosticEvent::KIND_HTTP_ERROR, DiagnosticEvent::SOURCE_DAV, 405, 'Method Not Allowed');

        Livewire::test(FailedLoginsTable::class)
            ->assertSee('неверный пароль')
            ->assertSee('отозван')
            ->assertDontSee('bad password');

        Livewire::test(DavErrorsTable::class)
            ->assertSee('Метод не разрешён')
            ->assertDontSee('Method Not Allowed');

        $this->assertSame(
            ['Method Not Allowed', 'bad password', 'revoked'],
            DiagnosticEvent::query()->orderBy('reason')->pluck('reason')->all(),
        );
    }

    public function test_email_taken_and_calendar_columns_are_russian(): void
    {
        App::setLocale('ru');
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));
        $ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm(['username' => 'bob', 'display_name' => 'Bob', 'email' => 'ADA@example.com'])
            ->call('create')
            ->assertHasFormErrors(['email'])
            ->assertSee('Этот адрес электронной почты уже используется.');

        Livewire::test(CalendarsRelationManager::class, ['ownerRecord' => $ada, 'pageClass' => EditUser::class])
            ->assertSee('События')
            ->assertSee('Задачи')
            ->assertSee('Заметки')
            ->assertDontSee('Tasks');
    }

    /**
     * Every panel page in a pseudo-locale: app strings are wrapped in ⟦…⟧ and
     * everything else falls back to Russian. Latin text left outside the
     * markers is an untranslated string, unless it is on the allowlist
     * (product and protocol names, sample user data, technical values).
     */
    public function test_pseudo_locale_leaves_no_untranslated_text_on_any_panel_page(): void
    {
        $this->usePseudoLocale();
        config(['nexusdav.default_locale' => 'en_XA', 'nexusdav.version' => 'dev']);

        $root = User::factory()->create([
            'username' => 'root', 'display_name' => 'Root', 'email' => 'root@example.com',
            'is_admin' => true, 'is_active' => true, 'locale' => 'en_XA',
        ]);
        $ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->sampleData[] = app(AppPasswordService::class)->issue($ada, 'Pixel')['app_password']->public_id;
        $this->diagnosticEvent(DiagnosticEvent::KIND_AUTH_FAILED, DiagnosticEvent::SOURCE_DAV, 401, 'bad secret');
        $this->diagnosticEvent(DiagnosticEvent::KIND_HTTP_ERROR, DiagnosticEvent::SOURCE_DAV, 404, 'Not Found');

        $leaks = [];
        $leaks['login'] = $this->untranslated($this->get('/admin/login')->assertOk()->getContent());

        $this->actingAs($root);

        foreach (['/admin', '/admin/users', '/admin/users/create', '/admin/users/'.$ada->getKey().'/edit', '/admin/settings', '/admin/configuration', '/admin/diagnostics'] as $page) {
            $leaks[$page] = $this->untranslated($this->get($page)->assertOk()->getContent());
        }

        App::setLocale('en_XA');

        foreach ([CalendarsRelationManager::class, AddressBooksRelationManager::class, AppPasswordsRelationManager::class] as $manager) {
            $leaks[class_basename($manager)] = $this->untranslated(Livewire::test($manager, ['ownerRecord' => $ada, 'pageClass' => EditUser::class])->html());
        }

        foreach ([ServerStatusWidget::class, ServicesWidget::class, CountsWidget::class, AttentionWidget::class, FailedLoginsTable::class, DavErrorsTable::class, AppPasswordsTable::class] as $widget) {
            $leaks[class_basename($widget)] = $this->untranslated(Livewire::test($widget)->html());
        }

        $leaks['language menu'] = $this->untranslated(Livewire::test(Topbar::class)->mountAction('language')->html());

        $this->assertSame([], array_filter($leaks), 'Untranslated text found: '.json_encode(array_filter($leaks), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function usePseudoLocale(): void
    {
        $pseudo = [];

        foreach (json_decode(File::get(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR) as $key => $_) {
            $pseudo[$key] = $key === '_language_name' ? 'Pseudo' : '⟦'.(str_contains($key, '|') ? ':count' : $key).'⟧';
        }

        $directory = storage_path('framework/testing/pseudo-locale');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/en_XA.json', json_encode($pseudo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($directory));

        app('translation.loader')->addJsonPath($directory);
        // Filament's and Laravel's own strings come from their Russian files.
        app('translator')->setFallback('ru');
        $this->app->instance(LocaleCatalog::class, new class extends LocaleCatalog
        {
            public function all(): array
            {
                return parent::all() + ['en_XA' => 'Pseudo'];
            }
        });
    }

    /**
     * @return list<string>
     */
    private function untranslated(string $html): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $texts = [];

        foreach ($xpath->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::svg)] | //@placeholder | //@title') ?: [] as $node) {
            $texts[] = $node->nodeValue ?? '';
        }

        $allowed = [
            '/[\w.+-]+@example\.com/u',
            ...array_map(fn (string $value): string => '/'.preg_quote($value, '/').'/u', $this->sampleData),
            '/\b(NexusDAV|CalDAV|CardDAV|WebDAV|DAV|URI|HTTPS|SQLite|PostgreSQL|VTODO|VJOURNAL|UTC|dev)\b/u',
            '/\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b/u',
            '/\b(PROPFIND|REPORT|GET|PUT|DELETE|Tab|Escape)\b/u',
            '/\b(Root|root|Ada|ada|Pixel|Calendar|Contacts|principals|default|English|Pseudo|Debug|Info|Notice|Warning|Error|Critical|Alert|Emergency)\b/u',
            '/\/[\w\/.-]*/u',
            '/#[0-9A-Fa-f]{6,8}\b/u',
            '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|AM|PM)\b/u',
            '/\b[0-9a-f]{8,}(-[0-9a-f]+)*\b/u',
        ];
        $leaks = [];

        foreach ($texts as $text) {
            do {
                $text = preg_replace('/⟦[^⟦⟧]*⟧/u', '', $text, -1, $count) ?? '';
            } while ($count > 0);

            $remaining = trim((string) preg_replace($allowed, '', $text));

            if (preg_match('/\p{Latin}/u', $remaining) === 1) {
                $leaks[] = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            }
        }

        return array_values(array_unique($leaks));
    }

    private function diagnosticEvent(string $kind, string $source, int $status, string $reason): DiagnosticEvent
    {
        return DiagnosticEvent::query()->create([
            'occurred_at' => now(),
            'source' => $source,
            'kind' => $kind,
            'ip' => '127.0.0.1',
            'username' => 'ada',
            'public_id' => null,
            'method' => 'PROPFIND',
            'path' => '/dav/',
            'status' => $status,
            'reason' => $reason,
            'repeat_count' => 1,
            'bucket' => intdiv(now()->getTimestamp(), 60),
        ]);
    }
}

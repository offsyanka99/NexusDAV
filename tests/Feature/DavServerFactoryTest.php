<?php

namespace Tests\Feature;

use App\Http\Controllers\DavController;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\DavServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Sabre\DAV\Server;
use Tests\TestCase;

class DavServerFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_loads_caldav_carddav_and_sync_by_default(): void
    {
        $server = app(DavServerFactory::class)->make();

        $this->assertSame('/dav/', $server->getBaseUri());
        $this->assertSame(['addressbooks', 'calendars', 'principals'], $this->nodeNames($server));
        $this->assertNotNull($server->getPlugin('caldav'));
        $this->assertNotNull($server->getPlugin('carddav'));
        $this->assertNotNull($server->getPlugin('sync'));
        $this->assertNotNull($server->getPlugin('locks'));
        $this->assertNotNull($server->getPlugin('property-storage'));
        $this->assertNotNull($server->getPlugin('acl'));
        $this->assertNotNull($server->getPlugin('auth'));
        $this->assertNull($server->getPlugin('browser'));
        $this->assertNull($server->getPlugin('sharing'));
        $this->assertNull($server->getPlugin('caldav-sharing'));
        $this->assertSame([
            'caldav' => true,
            'carddav' => true,
            'tasks' => true,
            'notes' => true,
        ], app(DavServerFactory::class)->features());
        $this->assertSame(
            ['VEVENT', 'VTODO', 'VJOURNAL'],
            (new CalendarBackend(DB::connection()->getPdo()))->allowedComponents(),
        );
    }

    public function test_factory_omits_plugins_when_a_feature_is_off(): void
    {
        config([
            'nexusdav.features.caldav' => false,
            'nexusdav.features.tasks' => false,
            'nexusdav.features.notes' => false,
        ]);

        $server = app(DavServerFactory::class)->make();

        $this->assertSame(['addressbooks', 'principals'], $this->nodeNames($server));
        $this->assertNull($server->getPlugin('caldav'));
        $this->assertNotNull($server->getPlugin('carddav'));
        $this->assertNotNull($server->getPlugin('sync'));
        $this->assertNotNull($server->getPlugin('acl'));
        $this->assertNotNull($server->getPlugin('auth'));
        $this->assertNull($server->getPlugin('browser'));
        $this->assertSame([
            'caldav' => false,
            'carddav' => true,
            'tasks' => false,
            'notes' => false,
        ], app(DavServerFactory::class)->features());
        $this->assertSame(['VEVENT'], (new CalendarBackend(DB::connection()->getPdo()))->allowedComponents());
    }

    public function test_carddav_off_drops_address_books_and_sync_follows_either_protocol(): void
    {
        config([
            'nexusdav.features.caldav' => false,
            'nexusdav.features.carddav' => false,
        ]);

        $server = app(DavServerFactory::class)->make();

        $this->assertSame(['principals'], $this->nodeNames($server));
        $this->assertNull($server->getPlugin('caldav'));
        $this->assertNull($server->getPlugin('carddav'));
        $this->assertNull($server->getPlugin('sync'));
        $this->assertNotNull($server->getPlugin('locks'));
        $this->assertNotNull($server->getPlugin('acl'));
        $this->assertNotNull($server->getPlugin('auth'));
        $this->assertNull($server->getPlugin('browser'));
    }

    public function test_dav_routes_are_outside_the_web_middleware(): void
    {
        $dav = $this->route('dav/{path?}');

        $this->assertNotNull($dav);
        $this->assertEqualsCanonicalizing(
            ['OPTIONS', 'GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PROPFIND', 'PROPPATCH', 'REPORT', 'MKCOL', 'MKCALENDAR', 'COPY', 'MOVE', 'LOCK', 'UNLOCK'],
            $dav->methods(),
        );
        $this->assertStringContainsString(DavController::class, $dav->getActionName());
        $this->assertOutsideWeb($dav);

        $caldav = $this->route('.well-known/caldav');
        $carddav = $this->route('.well-known/carddav');

        $this->assertNotNull($caldav);
        $this->assertNotNull($carddav);
        $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'PROPFIND', 'OPTIONS'], $caldav->methods());
        $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'PROPFIND', 'OPTIONS'], $carddav->methods());
        $this->assertOutsideWeb($caldav);
        $this->assertOutsideWeb($carddav);
    }

    private function route(string $uri): ?Route
    {
        foreach (RouteFacade::getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }

    private function assertOutsideWeb(Route $route): void
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $name = (string) $middleware;

            $this->assertNotSame('web', $name);
            $this->assertStringNotContainsString('StartSession', $name);
            $this->assertStringNotContainsString('VerifyCsrfToken', $name);
            $this->assertStringNotContainsString('ValidateCsrfToken', $name);
        }
    }

    /**
     * @return list<string>
     */
    private function nodeNames(Server $server): array
    {
        $names = [];

        foreach ($server->tree->getNodeForPath('')->getChildren() as $node) {
            $names[] = $node->getName();
        }

        sort($names);

        return $names;
    }
}

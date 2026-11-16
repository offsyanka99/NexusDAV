<?php

namespace App\Portal;

use Illuminate\Support\Facades\File;
use LogicException;
use ReflectionClass;

/**
 * The registry of portal apps: every PortalApp in app/Portal/Apps, plus any
 * registered with register() (tests). Ordered by sort(), then id.
 */
final class PortalApps
{
    /**
     * @var list<class-string<PortalApp>>
     */
    private array $registered = [];

    /**
     * @var list<PortalApp>|null
     */
    private ?array $apps = null;

    /**
     * @param  class-string<PortalApp>  $class
     */
    public function register(string $class): void
    {
        $this->registered[] = $class;
        $this->apps = null;
    }

    /**
     * @return list<PortalApp>
     */
    public function all(): array
    {
        if ($this->apps !== null) {
            return $this->apps;
        }

        $apps = [];

        foreach (array_unique([...$this->discover(), ...$this->registered]) as $class) {
            $app = app($class);

            if (isset($apps[$app->id()])) {
                throw new LogicException('Two portal apps have the id '.$app->id().'.');
            }

            $apps[$app->id()] = $app;
        }

        usort($apps, static fn (PortalApp $a, PortalApp $b): int => [$a->sort(), $a->id()] <=> [$b->sort(), $b->id()]);

        return $this->apps = $apps;
    }

    /**
     * The apps whose switches are all on.
     *
     * @return list<PortalApp>
     */
    public function visible(): array
    {
        return array_values(array_filter($this->all(), static fn (PortalApp $app): bool => $app->isVisible()));
    }

    public function find(string $id): ?PortalApp
    {
        foreach ($this->all() as $app) {
            if ($app->id() === $id) {
                return $app;
            }
        }

        return null;
    }

    /**
     * The app a page belongs to, or null for a page of no app (Account).
     */
    public function forPage(string $page): ?PortalApp
    {
        foreach ($this->all() as $app) {
            if (in_array($page, $app->pages(), true)) {
                return $app;
            }
        }

        return null;
    }

    /**
     * The app a Dashboard card belongs to.
     */
    public function forCard(string $card): ?PortalApp
    {
        foreach ($this->all() as $app) {
            if (in_array($card, $app->cards(), true)) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Every page of every app, for the panel's routes.
     *
     * @return list<class-string<PortalPage>>
     */
    public function pages(): array
    {
        $pages = [];

        foreach ($this->all() as $app) {
            array_push($pages, ...$app->pages());
        }

        return array_values(array_unique($pages));
    }

    /**
     * The Dashboard cards of the visible apps, in app order.
     *
     * @return list<class-string<DashboardCard>>
     */
    public function cards(): array
    {
        $cards = [];

        foreach ($this->visible() as $app) {
            array_push($cards, ...$app->cards());
        }

        return $cards;
    }

    /**
     * @return list<class-string<PortalApp>>
     */
    private function discover(): array
    {
        $classes = [];

        foreach (File::glob(app_path('Portal/Apps/*.php')) as $file) {
            $class = 'App\\Portal\\Apps\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, PortalApp::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}

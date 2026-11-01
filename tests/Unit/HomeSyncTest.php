<?php

namespace Tests\Unit;

use App\Services\Dav\HomeSync;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\INode;

class HomeSyncTest extends TestCase
{
    public function test_numeric_names_round_trip_and_a_removed_child_is_deleted(): void
    {
        $home = $this->home(['2024', '0123', 'personal']);

        $first = $home->getChanges(null, 1);
        $this->assertIsArray($first);
        $this->assertSame(['0123', '2024', 'personal'], $first['added']);
        $this->assertSame([], $first['modified']);
        $this->assertSame([], $first['deleted']);
        $this->assertSame($first['syncToken'], $home->getSyncToken());

        $again = $home->getChanges($first['syncToken'], 1);
        $this->assertIsArray($again);
        $this->assertSame([], $again['added']);
        $this->assertSame([], $again['modified']);
        $this->assertSame([], $again['deleted']);
        $this->assertSame($first['syncToken'], $again['syncToken']);

        $home->names = ['0123', 'personal'];

        $deleted = $home->getChanges($first['syncToken'], 1);
        $this->assertIsArray($deleted);
        $this->assertSame([], $deleted['added']);
        $this->assertSame(['2024'], $deleted['deleted']);

        $this->assertNull($home->getChanges('home-1.not-a-snapshot', 1));
    }

    public function test_an_oversized_snapshot_is_not_issued(): void
    {
        $names = [];

        for ($i = 0; $i < 1500; $i++) {
            $names[] = str_repeat('n', 80).$i;
        }

        $home = $this->home($names);

        $this->assertNull($home->getSyncToken());
        $this->assertNull($home->getChanges(null, 1));
        $this->assertNull($home->getChanges('', 1));
    }

    /**
     * @param  list<string>  $names
     */
    private function home(array $names): object
    {
        return new class($names)
        {
            use HomeSync;

            /** @param list<string> $names */
            public function __construct(public array $names) {}

            /**
             * @return list<INode>
             */
            public function getChildren(): array
            {
                $children = [];

                foreach ($this->names as $name) {
                    $children[] = new class($name) implements INode
                    {
                        public function __construct(private string $name) {}

                        public function delete() {}

                        public function getName()
                        {
                            return $this->name;
                        }

                        public function setName($name) {}

                        public function getLastModified()
                        {
                            return null;
                        }
                    };
                }

                return $children;
            }
        };
    }
}

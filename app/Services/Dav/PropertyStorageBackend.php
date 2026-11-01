<?php

namespace App\Services\Dav;

use Sabre\DAV\PropertyStorage\Backend\PDO as SabrePropertyBackend;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Property\Complex;
use Sabre\Uri;

/**
 * sabre's property storage queried once per resource, so a depth-1 PROPFIND
 * on a calendar of 500 events ran 500 queries. This loads the dead properties
 * of every child of a collection in one query and answers the siblings from
 * that. The cache lives for one request and is dropped on any write.
 * Property names longer than the column are refused with 403.
 */
final class PropertyStorageBackend extends SabrePropertyBackend
{
    /**
     * @var array<string, array<string, list<array{name: string, value: mixed, valuetype: mixed}>>>
     */
    private array $byParent = [];

    public function propFind($path, PropFind $propFind)
    {
        if (! $propFind->isAllProps() && count($propFind->get404Properties()) === 0) {
            return;
        }

        foreach ($this->rowsFor((string) $path) as $row) {
            $value = $row['value'] === null ? null : BinaryValue::toString($row['value']);

            switch ($row['valuetype'] === null ? null : (int) $row['valuetype']) {
                case null:
                case self::VT_STRING:
                    $propFind->set($row['name'], $value);
                    break;
                case self::VT_XML:
                    $propFind->set($row['name'], new Complex((string) $value));
                    break;
                case self::VT_OBJECT:
                    $propFind->set($row['name'], unserialize((string) $value));
                    break;
            }
        }
    }

    public function propPatch($path, PropPatch $propPatch)
    {
        $this->byParent = [];

        foreach ($propPatch->getRemainingMutations() as $name) {
            if (mb_strlen((string) $name) > DavLimits::PROPERTY_NAME) {
                $propPatch->setResultCode($name, 403);
            }
        }

        parent::propPatch($path, $propPatch);
    }

    public function delete($path)
    {
        $this->byParent = [];

        parent::delete($path);
    }

    public function move($source, $destination)
    {
        $this->byParent = [];

        parent::move($source, $destination);
    }

    /**
     * @return list<array{name: string, value: mixed, valuetype: mixed}>
     */
    private function rowsFor(string $path): array
    {
        if ($path === '') {
            return $this->select('SELECT path, name, value, valuetype FROM '.$this->tableName.' WHERE path = ?', [''])[''] ?? [];
        }

        $parent = Uri\split($path)[0];

        if (! array_key_exists($parent, $this->byParent)) {
            $this->byParent[$parent] = $this->children($parent);
        }

        return $this->byParent[$parent][$path] ?? [];
    }

    /**
     * Rows for the direct children of $parent, grouped by path.
     *
     * @return array<string, list<array{name: string, value: mixed, valuetype: mixed}>>
     */
    private function children(string $parent): array
    {
        if ($parent === '') {
            $rows = $this->select(
                'SELECT path, name, value, valuetype FROM '.$this->tableName." WHERE path NOT LIKE '%/%'",
                [],
            );
        } else {
            $prefix = strtr($parent, ['=' => '==', '%' => '=%', '_' => '=_']).'/';
            $rows = $this->select(
                'SELECT path, name, value, valuetype FROM '.$this->tableName
                ." WHERE path LIKE ? ESCAPE '=' AND path NOT LIKE ? ESCAPE '='",
                [$prefix.'%', $prefix.'%/%'],
            );
        }

        // SQLite LIKE ignores case, so keep only the exact parent.
        $children = [];

        foreach ($rows as $path => $pathRows) {
            if (Uri\split((string) $path)[0] === $parent) {
                $children[(string) $path] = $pathRows;
            }
        }

        return $children;
    }

    /**
     * @param  list<string>  $bindings
     * @return array<string, list<array{name: string, value: mixed, valuetype: mixed}>>
     */
    private function select(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $grouped = [];

        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $value = $row['value'];

            // A bytea stream must be read before the next row is fetched.
            if (is_resource($value)) {
                $value = BinaryValue::toString($value);
            }

            $grouped[(string) $row['path']][] = [
                'name' => (string) $row['name'],
                'value' => $value,
                'valuetype' => $row['valuetype'],
            ];
        }

        return $grouped;
    }
}

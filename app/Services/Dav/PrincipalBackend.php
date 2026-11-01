<?php

namespace App\Services\Dav;

use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;
use Sabre\Uri;

/**
 * Reads the Laravel principals table. sabre's own principals table is not used.
 * Groups are empty in Version 1. Display name can change; email cannot,
 * because the admin panel owns the address and UserService keeps the two
 * copies in sync.
 */
final class PrincipalBackend extends AbstractBackend
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getPrincipalsByPrefix($prefixPath)
    {
        $prefix = $this->prefix($prefixPath);
        $principals = [];

        foreach ($this->principalsUnder($prefix)->get() as $principal) {
            $principals[] = $this->toSabre($principal);
        }

        return $principals;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPrincipalByPath($path)
    {
        $principal = Principal::query()->where('uri', (string) $path)->first();

        if (! $principal instanceof Principal) {
            return null;
        }

        return $this->toSabre($principal);
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        $propPatch->handle('{DAV:}displayname', function (mixed $value) use ($path): bool {
            if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 255) {
                return false;
            }

            $principal = Principal::query()->where('uri', (string) $path)->first();
            $user = $principal?->user;

            if (! $principal instanceof Principal || ! $user instanceof User) {
                return false;
            }

            $user->display_name = trim($value);
            $user->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $searchProperties
     * @return list<string>
     */
    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        if ($searchProperties === []) {
            return [];
        }

        $columns = [];

        foreach ($searchProperties as $property => $value) {
            $column = match ($property) {
                '{DAV:}displayname' => 'display_name',
                '{http://sabredav.org/ns}email-address' => 'email',
                default => null,
            };

            if ($column === null || ! is_string($value)) {
                return [];
            }

            $columns[$column] = $value;
        }

        $query = Principal::query();
        $combineWithOr = $test === 'anyof';
        $first = true;

        foreach ($columns as $column => $value) {
            $pattern = '%'.$this->escapeLike($value).'%';
            $sql = 'lower('.$column.') LIKE lower(?) ESCAPE ?';

            if ($first || ! $combineWithOr) {
                $query->whereRaw($sql, [$pattern, '\\']);
            } else {
                $query->orWhereRaw($sql, [$pattern, '\\']);
            }

            $first = false;
        }

        $prefix = $this->prefix($prefixPath);
        $uris = [];

        foreach ($query->orderBy('uri')->get() as $principal) {
            if ($this->prefixOf($principal->uri) === $prefix) {
                $uris[] = $principal->uri;
            }
        }

        return $uris;
    }

    /**
     * @return string|null
     */
    public function findByUri($uri, $principalPrefix)
    {
        $parts = Uri\parse((string) $uri);

        if (! is_string($parts['path'] ?? null) || $parts['path'] === '') {
            return null;
        }

        $prefix = $this->prefix($principalPrefix);

        if (($parts['scheme'] ?? null) === 'mailto') {
            $principal = Principal::query()
                ->whereRaw('lower(email) = lower(?)', [$parts['path']])
                ->orderBy('id')
                ->first();

            if (! $principal instanceof Principal || $this->prefixOf($principal->uri) !== $prefix) {
                return null;
            }

            return $principal->uri;
        }

        $segments = Uri\split($parts['path']);

        if ($segments[0] !== $prefix || ! is_string($segments[1]) || $segments[1] === '') {
            return null;
        }

        $principal = Principal::query()->where('uri', $parts['path'])->first();

        return $principal instanceof Principal ? $principal->uri : null;
    }

    /**
     * @return list<string>
     */
    public function getGroupMemberSet($principal)
    {
        $this->requirePrincipal((string) $principal);

        return [];
    }

    /**
     * @return list<string>
     */
    public function getGroupMembership($principal)
    {
        $this->requirePrincipal((string) $principal);

        return [];
    }

    /**
     * @param  list<string>  $members
     */
    public function setGroupMemberSet($principal, array $members)
    {
        $this->requirePrincipal((string) $principal);

        throw new Forbidden('Groups are not available.');
    }

    private function requirePrincipal(string $uri): Principal
    {
        $principal = Principal::query()->where('uri', $uri)->first();

        if (! $principal instanceof Principal) {
            throw new Exception('Principal not found');
        }

        return $principal;
    }

    /**
     * @return Builder<Principal>
     */
    private function principalsUnder(string $prefix): Builder
    {
        return Principal::query()
            ->whereRaw('uri LIKE ? ESCAPE ?', [$this->escapeLike($prefix).'/%', '\\'])
            ->orderBy('uri');
    }

    /**
     * @return array<string, mixed>
     */
    private function toSabre(Principal $principal): array
    {
        $row = [
            'id' => (int) $principal->getKey(),
            'uri' => $principal->uri,
        ];

        if ($principal->display_name !== '') {
            $row['{DAV:}displayname'] = $principal->display_name;
        }

        if ($principal->email !== '') {
            $row['{http://sabredav.org/ns}email-address'] = $principal->email;
        }

        return $row;
    }

    private function prefix(mixed $prefix): string
    {
        return rtrim((string) $prefix, '/');
    }

    private function prefixOf(string $uri): string
    {
        $segments = Uri\split($uri);

        return is_string($segments[0]) ? $segments[0] : '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

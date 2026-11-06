<?php

namespace App\Services\Dav;

use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\SharedCalendar;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;

/**
 * A calendar in someone's home, owned or shared.
 *
 * DAV:owner is this principal, including for a share. Privileges come from
 * the instance access, never from that owner property: a read-only sharee
 * must not receive write. No share privilege and no calendar-proxy principal.
 */
final class CalendarNode extends SharedCalendar
{
    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getACL()
    {
        $principal = (string) $this->calendarInfo['principaluri'];

        if ($this->getShareAccess() === SharingPlugin::ACCESS_READ) {
            return $this->aces($principal, ['{DAV:}read', '{DAV:}write-properties']);
        }

        if ($this->getShareAccess() === SharingPlugin::ACCESS_READWRITE) {
            return $this->aces($principal, ['{DAV:}read', '{DAV:}write']);
        }

        $acl = $this->aces($principal, ['{DAV:}read', '{DAV:}write']);
        $acl[] = [
            'privilege' => '{'.Plugin::NS_CALDAV.'}read-free-busy',
            'principal' => '{DAV:}authenticated',
            'protected' => true,
        ];

        return $acl;
    }

    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getChildACL()
    {
        $principal = (string) $this->calendarInfo['principaluri'];

        if ($this->getShareAccess() === SharingPlugin::ACCESS_READ) {
            return $this->aces($principal, ['{DAV:}read']);
        }

        return $this->aces($principal, ['{DAV:}read', '{DAV:}write']);
    }

    /**
     * @param  list<string>  $privileges
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    private function aces(string $principal, array $privileges): array
    {
        $acl = [];

        foreach ($privileges as $privilege) {
            $acl[] = [
                'privilege' => $privilege,
                'principal' => $principal,
                'protected' => true,
            ];
        }

        return $acl;
    }
}

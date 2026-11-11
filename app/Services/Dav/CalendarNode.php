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
 *
 * A holiday calendar's events are written by the server only: its owner may
 * rename it, recolour it and delete it, but gets read on its events, like a
 * read-only sharee. sabre then refuses PUT, DELETE, COPY and MOVE into or out
 * of it (bind, unbind, write-content) and PROPPATCH of an event.
 */
final class CalendarNode extends SharedCalendar
{
    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getACL()
    {
        $principal = (string) $this->calendarInfo['principaluri'];
        $access = $this->getShareAccess();

        if ($access === SharingPlugin::ACCESS_READ) {
            return $this->aces($principal, ['{DAV:}read', '{DAV:}write-properties']);
        }

        $acl = $this->aces($principal, $this->holiday() ? ['{DAV:}read', '{DAV:}write-properties'] : ['{DAV:}read', '{DAV:}write']);

        if ($access === SharingPlugin::ACCESS_READWRITE) {
            return $acl;
        }

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

        if ($this->getShareAccess() === SharingPlugin::ACCESS_READ || $this->holiday()) {
            return $this->aces($principal, ['{DAV:}read']);
        }

        return $this->aces($principal, ['{DAV:}read', '{DAV:}write']);
    }

    private function holiday(): bool
    {
        return ($this->calendarInfo[CalendarBackend::HOLIDAY] ?? false) === true;
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

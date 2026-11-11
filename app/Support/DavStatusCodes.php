<?php

namespace App\Support;

/**
 * The status codes a DAV client can get from this server, for the "i" dialog
 * on Diagnostics: what the code means here, and what to look at. A 502, a 504
 * or a 413 from the reverse proxy never reaches the app, so it is not listed.
 */
final class DavStatusCodes
{
    /**
     * @return list<array{code: int, name: string, meaning: string, check: string}>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 400,
                'name' => __('Bad Request'),
                'meaning' => __('The request or a value in it is not valid: a name with a control character or over its length limit, data that is cut off, or a malformed header.'),
                'check' => __('The client app and its version. The log has the details under the Request ID.'),
            ],
            [
                'code' => 401,
                'name' => __('Unauthorized'),
                'meaning' => __('The sign-in failed. The reason says why: unknown user, wrong or revoked app password, an expired password, a disabled user, or the main password used on DAV. A first request without credentials is normal and is not listed.'),
                'check' => __('The username and the app password on the device, and the App passwords table below.'),
            ],
            [
                'code' => 403,
                'name' => __('Forbidden'),
                'meaning' => __('The user is signed in but the server refuses the request: a service that is switched off (CalDAV, CardDAV or Files), a write to a read-only share or a holiday calendar, a client trying to share, a path with "..", or copying between Files and calendars.'),
                'check' => __('Settings, the access of the share, and the path.'),
            ],
            [
                'code' => 404,
                'name' => __('Not Found'),
                'meaning' => __('There is nothing at that path, it belongs to another user, or it is a shared collection while Sharing is off. Clients also ask first whether a name is free before they create it, so a 404 before an upload or a new folder is normal.'),
                'check' => __('The URL, and the username in it.'),
            ],
            [
                'code' => 405,
                'name' => __('Method Not Allowed'),
                'meaning' => __('That method cannot be used on that path. Usually a client creating a folder, calendar or address book that already exists, which is harmless.'),
                'check' => __('Nothing, unless a client reports a problem.'),
            ],
            [
                'code' => 409,
                'name' => __('Conflict'),
                'meaning' => __("The parent folder or collection is missing, or a copy or move goes into itself or into another user's home."),
                'check' => __('The destination path of the client.'),
            ],
            [
                'code' => 412,
                'name' => __('Precondition Failed'),
                'meaning' => __('Another device changed the item first, so this write was refused instead of replacing it. The client fetches the new version on its next sync. Nothing is lost.'),
                'check' => __('Nothing. It happens when two devices edit the same item.'),
            ],
            [
                'code' => 413,
                'name' => __('Content Too Large'),
                'meaning' => __('An upload is bigger than the Maximum upload size (Files), or a calendar or contact body is over 128 MB. Nothing is saved.'),
                'check' => __('Maximum upload size on the Settings page. A 413 from the reverse proxy is in the proxy log, not here.'),
            ],
            [
                'code' => 415,
                'name' => __('Unsupported Media Type'),
                'meaning' => __('The data is not valid calendar (iCalendar) or contact (vCard) data, so it is refused.'),
                'check' => __('The client app. The log has the details under the Request ID.'),
            ],
            [
                'code' => 416,
                'name' => __('Range Not Satisfiable'),
                'meaning' => __('A client asked for a part of a file that is past its end, for example when it resumes a download of a file that has since changed.'),
                'check' => __('Nothing. The client downloads the file again.'),
            ],
            [
                'code' => 423,
                'name' => __('Locked'),
                'meaning' => __('The file or folder is locked by another client, or a write came without the lock token.'),
                'check' => __('Whether another device or program still has the file open. A lock ends when it times out.'),
            ],
            [
                'code' => 500,
                'name' => __('Internal Server Error'),
                'meaning' => __('The server failed unexpectedly. This is a bug or a broken installation, not a mistake of the client.'),
                'check' => __('Search the container log for the Request ID, and report the problem with that line.'),
            ],
            [
                'code' => 503,
                'name' => __('Service Unavailable'),
                'meaning' => __('The database stayed busy after the server tried again. The answer carries Retry-After, and the client sends the item again later. It happens mostly on SQLite with many uploads at once.'),
                'check' => __('PostgreSQL handles many parallel writes better. Check how often it happens.'),
            ],
            [
                'code' => 507,
                'name' => __('Insufficient Storage'),
                'meaning' => __('The disk or quota for the data volume is full. The upload was not saved and left nothing behind.'),
                'check' => __('Free space on the data volume, and the Free space figure on the Dashboard.'),
            ],
        ];
    }
}

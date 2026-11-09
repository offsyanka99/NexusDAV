<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Services\Dav\SyncToken;
use Illuminate\Support\Facades\DB;
use Sabre\DAV\Sync\Plugin as SyncPlugin;

/**
 * The push-message body (draft-bitfire-webdav-push-00, "Push Message"): the
 * topic, a content update with the collection's current sync token, and a
 * property update, each only when the subscription asked for it.
 */
final class PushMessage
{
    /**
     * The parts of a merged change this subscription gets.
     *
     * @return array{content: bool, property: bool}
     */
    public static function parts(PushSubscription $subscription, ?int $contentDepth, bool $property): array
    {
        $granted = $subscription->content_update_depth;

        return [
            'content' => $contentDepth !== null
                && $granted !== null
                && PushTopics::covers($granted, PushTopics::DEPTHS[min(2, max(0, $contentDepth))]),
            'property' => $property && $subscription->property_update_depth !== null,
        ];
    }

    public static function xml(string $topic, bool $content, ?string $syncToken, bool $property): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<P:push-message xmlns:D="DAV:" xmlns:P="https://bitfire.at/webdav-push">'
            .'<P:topic>'.self::escape($topic).'</P:topic>';

        if ($content) {
            $xml .= $syncToken === null
                ? '<P:content-update/>'
                : '<P:content-update><D:sync-token>'.self::escape($syncToken).'</D:sync-token></P:content-update>';
        }

        if ($property) {
            $xml .= '<P:property-update/>';
        }

        return $xml.'</P:push-message>'."\n";
    }

    /**
     * The collection's sync token as a PROPFIND shows it, read when the
     * message is built, so a merged message carries the latest one. Null
     * for a file folder or a collection that is gone.
     */
    public static function syncToken(?string $source): ?string
    {
        if ($source === null || preg_match('/^(calendar|addressbook):([1-9][0-9]*)$/', $source, $match) !== 1) {
            return null;
        }

        $id = (int) $match[2];
        $token = DB::table($match[1] === 'calendar' ? 'calendars' : 'addressbooks')->where('id', $id)->value('synctoken');

        return $token === null ? null : SyncPlugin::SYNCTOKEN_PREFIX.SyncToken::format($id, (int) $token);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

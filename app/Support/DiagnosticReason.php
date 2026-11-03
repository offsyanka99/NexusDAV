<?php

namespace App\Support;

/**
 * Display labels for the reasons stored in diagnostic_events. The stored
 * value stays English because log searches depend on it; only the panel
 * shows a translated label. Unknown reasons are shown as stored.
 */
final class DiagnosticReason
{
    public static function label(string $reason): string
    {
        return match ($reason) {
            'unknown user' => __('unknown user'),
            'bad secret' => __('bad secret'),
            'revoked' => __('revoked'),
            'expired' => __('expired'),
            'user disabled' => __('user disabled'),
            'main password used' => __('main password used'),
            'panel refused' => __('panel refused'),
            'bad password' => __('bad password'),
            'many usernames' => __('many usernames'),
            'many requests' => __('many requests'),
            'Bad Request' => __('Bad Request'),
            'Unauthorized' => __('Unauthorized'),
            'Forbidden' => __('Forbidden'),
            'Not Found' => __('Not Found'),
            'Method Not Allowed' => __('Method Not Allowed'),
            'Not Acceptable' => __('Not Acceptable'),
            'Conflict' => __('Conflict'),
            'Gone' => __('Gone'),
            'Precondition Failed' => __('Precondition Failed'),
            'Content Too Large' => __('Content Too Large'),
            'URI Too Long' => __('URI Too Long'),
            'Unsupported Media Type' => __('Unsupported Media Type'),
            'Range Not Satisfiable' => __('Range Not Satisfiable'),
            'Unprocessable Content' => __('Unprocessable Content'),
            'Locked' => __('Locked'),
            'Failed Dependency' => __('Failed Dependency'),
            'Too Many Requests' => __('Too Many Requests'),
            'Internal Server Error' => __('Internal Server Error'),
            'Not Implemented' => __('Not Implemented'),
            'Bad Gateway' => __('Bad Gateway'),
            'Service Unavailable' => __('Service Unavailable'),
            'Insufficient Storage' => __('Insufficient Storage'),
            'HTTP error' => __('HTTP error'),
            default => $reason,
        };
    }
}

<?php

namespace App\Support;

final class PanelErrorTranslator
{
    public static function translate(string $message): string
    {
        $translated = match ($message) {
            'The last active admin cannot be deleted. Create another active admin first.' => __('The last active admin cannot be deleted. Create another active admin first.'),
            'The last active admin cannot be disabled or demoted. Create another active admin first.' => __('The last active admin cannot be disabled or demoted. Create another active admin first.'),
            'Username, display name, and email are required.' => __('Username, display name, and email are required.'),
            'Username must be lowercase and URL-safe.' => __('Username must be lowercase and URL-safe.'),
            'Display name is required.' => __('Display name is required.'),
            'Email must be a valid email address.' => __('Email must be a valid email address.'),
            'The email has already been taken.' => __('The email has already been taken.'),
            'An admin must have a password.' => __('An admin must have a password.'),
            'The user could not be deleted.' => __('The user could not be deleted.'),
            'The principal could not be created.' => __('The principal could not be created.'),
            'Display name is too long.' => __('Display name is too long.'),
            'Color must be #RRGGBB.' => __('Color must be #RRGGBB.'),
            'That collection belongs to another user.' => __('That collection belongs to another user.'),
            'App password name must be between 1 and 255 characters.' => __('App password name must be between 1 and 255 characters.'),
            default => null,
        };

        if (is_string($translated)) {
            return $translated;
        }

        if (preg_match('/^The password must be at least (\d+) characters\.$/', $message, $matches) === 1) {
            return __('The password must be at least :count characters.', ['count' => $matches[1]]);
        }

        if (preg_match('/^The value for (.+) is not valid\.$/', $message, $matches) === 1) {
            return __('The value for :name is not valid.', ['name' => $matches[1]]);
        }

        return $message;
    }
}

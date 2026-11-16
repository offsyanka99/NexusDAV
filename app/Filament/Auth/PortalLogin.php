<?php

namespace App\Filament\Auth;

use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AuthThrottle;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/**
 * The user portal sign-in: email or username, and the main password. App
 * passwords stay DAV-only. The same limits as the admin sign-in, counted
 * under the source portal.
 */
class PortalLogin extends Login
{
    protected function throttleSource(): string
    {
        return DiagnosticEvent::SOURCE_PORTAL;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Email or username'))
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * A username never contains "@", an email always does.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = self::normalize($data['email'] ?? null);

        return [
            str_contains($login, '@') ? 'email' : 'username' => $login,
            'password' => $data['password'] ?? null,
        ];
    }

    /**
     * The email and the username of one account share one bucket, as on DAV.
     */
    protected function getRateLimitKey($method, $component = null)
    {
        $login = self::normalize($this->data['email'] ?? null);

        if (str_contains($login, '@')) {
            $username = User::query()->where('email', $login)->value('username');
            $login = is_string($username) ? $username : $login;
        }

        return app(AuthThrottle::class)->key($this->throttleSource(), $login);
    }

    private static function normalize(mixed $login): string
    {
        return is_string($login) ? mb_strtolower(trim($login)) : '';
    }
}

<?php

namespace App\Filament\Auth;

use App\Models\DiagnosticEvent;
use App\Services\AuthThrottle;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use SensitiveParameter;

class Login extends BaseLogin
{
    /**
     * Set when credentials already matched and the panel still refused the account.
     */
    private bool $panelRefused = false;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Email'))
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('Password'))
            ->hint(filament()->hasPasswordReset() ? new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="-1"> {{ __(\'filament-panels::auth/pages/login.actions.request_password_reset.label\') }}</x-filament::link>')) : null)
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }

    /**
     * Filament's key is the client IP alone, which lets one address lock every account.
     * The per-address limit counts failures only, across all usernames.
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null)
    {
        $throttle = app(AuthThrottle::class);

        if ($throttle->tooManyFromAddress()) {
            throw new TooManyRequestsException(
                static::class,
                'authenticate',
                request()->ip(),
                $throttle->secondsUntilAddressAvailable(),
            );
        }

        parent::rateLimit(
            $throttle->maxAttempts(),
            $throttle->decaySeconds(),
            $method,
            $component,
        );
    }

    protected function getRateLimitKey($method, $component = null)
    {
        $email = $this->data['email'] ?? '';

        return app(AuthThrottle::class)->key(
            DiagnosticEvent::SOURCE_ADMIN,
            is_string($email) ? $email : '',
        );
    }

    /**
     * Emails are stored in lower case, so the typed address matches in any case.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $credentials = parent::getCredentialsFromFormData($data);

        if (is_string($credentials['email'] ?? null)) {
            $credentials['email'] = mb_strtolower(trim($credentials['email']));
        }

        return $credentials;
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        $allowed = parent::isUserAllowedToAccessPanel($user);
        $this->panelRefused = ! $allowed;

        return $allowed;
    }

    /**
     * Filament emits one Failed event for a bad password and for a panel refusal.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function fireFailedEvent(Guard $guard, ?Authenticatable $user, #[SensitiveParameter] array $credentials): void
    {
        request()->attributes->set(
            'nexusdav.admin_auth_failure',
            $this->panelRefused ? 'panel' : 'credentials',
        );

        parent::fireFailedEvent($guard, $user, $credentials);
    }
}

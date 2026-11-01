<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Brute-force limit keyed by IP and the attempted username, plus a limit on
 * failures per client address across all usernames. The per-address limit
 * needs real client addresses, so it is off when every peer is trusted ("*"):
 * behind a proxy every client would then share the proxy's address.
 */
final class AuthThrottle
{
    public function tooMany(string $source, string $username): bool
    {
        return RateLimiter::tooManyAttempts($this->key($source, $username), $this->maxAttempts());
    }

    public function hit(string $source, string $username): void
    {
        RateLimiter::hit($this->key($source, $username), $this->decaySeconds());
    }

    public function key(string $source, string $username): string
    {
        $username = mb_strtolower(trim($username));

        return 'nexusdav-auth:'.hash('sha256', $source.'|'.$this->clientIp().'|'.$username);
    }

    /**
     * Failures from this client address, across all usernames and both login paths.
     */
    public function tooManyFromAddress(): bool
    {
        if (! $this->addressIsReal()) {
            return false;
        }

        return RateLimiter::tooManyAttempts($this->addressKey(), $this->maxAttemptsPerAddress());
    }

    public function hitAddress(): void
    {
        if (! $this->addressIsReal()) {
            return;
        }

        RateLimiter::hit($this->addressKey(), $this->decaySeconds());
    }

    public function secondsUntilAddressAvailable(): int
    {
        return RateLimiter::availableIn($this->addressKey());
    }

    /**
     * The client address can be told apart from other clients' addresses:
     * TRUSTED_PROXIES is an explicit list, not "*".
     */
    public function addressIsReal(): bool
    {
        return ! self::trustsEveryPeer();
    }

    public static function trustsEveryPeer(): bool
    {
        $value = config('nexusdav.trusted_proxies');

        if (! is_string($value)) {
            return false;
        }

        foreach (array_map(trim(...), explode(',', $value)) as $proxy) {
            if (in_array($proxy, ['*', '**', '0.0.0.0/0', '::/0'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * TRUSTED_PROXIES=* trusts every peer, so X-Forwarded-For is attacker input.
     * Only an explicitly listed proxy may supply the client address.
     */
    public function clientIp(): string
    {
        $request = request();
        $peer = (string) $request->server->get('REMOTE_ADDR');

        if (! $this->peerIsExplicitProxy($peer)) {
            return $peer;
        }

        $forwarded = $request->ip();

        return is_string($forwarded) && $forwarded !== '' ? $forwarded : $peer;
    }

    public function maxAttempts(): int
    {
        return (int) config('nexusdav.rate_limit.max_attempts', 5);
    }

    public function decaySeconds(): int
    {
        return (int) config('nexusdav.rate_limit.decay_seconds', 60);
    }

    public function maxAttemptsPerAddress(): int
    {
        return max(1, (int) config('nexusdav.rate_limit.max_attempts_per_address', 20));
    }

    private function addressKey(): string
    {
        return 'nexusdav-auth-address:'.hash('sha256', $this->clientIp());
    }

    private function peerIsExplicitProxy(string $peer): bool
    {
        if ($peer === '') {
            return false;
        }

        $proxies = array_values(array_filter(
            SymfonyRequest::getTrustedProxies(),
            static fn (string $proxy): bool => ! in_array($proxy, ['*', '0.0.0.0/0', '::/0'], true),
        ));

        if ($proxies === []) {
            return false;
        }

        return IpUtils::checkIp($peer, $proxies);
    }
}

<?php

namespace App\Services\Push;

/**
 * The SSRF guard for push resources (PR 37, docs/design/webdav-push.md).
 *
 * The push resource comes from the client, so the server must not be made
 * to POST to its own network: only https, and the host must resolve only
 * to public addresses. The name is resolved once here and the connection
 * is pinned to that address (PushHttpClient), so a second DNS answer
 * cannot point it elsewhere. With "allow private networks" on, plain http
 * and private addresses are allowed, for a push service on the LAN.
 *
 * Addresses that embed an IPv4 address (IPv4-mapped, 6to4, NAT64) are
 * judged by that IPv4 address; Teredo is refused.
 */
final class PushEndpointGuard
{
    /**
     * Non-global IPv4 ranges: RFC 6890 and the multicast and reserved blocks.
     */
    private const IPV4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * Non-global IPv6 ranges. ::/96 covers ::, ::1 and IPv4-compatible
     * addresses; 2001::/32 is Teredo.
     */
    private const IPV6_BLOCKED = [
        '::/96', '100::/64', '2001::/32', '2001:2::/48', '2001:10::/28', '2001:20::/28',
        '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public function __construct(private PushResolver $resolver) {}

    public function check(string $url): PushEndpoint
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = strtolower(trim((string) (is_array($parts) ? ($parts['host'] ?? '') : ''), '[]'));
        $private = PushConfig::allowPrivate();

        if (! is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new PushEndpointRefused('The push resource is not an absolute URL.');
        }

        if ($scheme !== 'https' && ! ($scheme === 'http' && $private)) {
            throw new PushEndpointRefused('The push resource does not use https.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) === 1) {
            $addresses = $this->resolver->addresses($host);
        } else {
            throw new PushEndpointRefused('The push resource has an invalid host name.');
        }

        if ($addresses === []) {
            throw new PushEndpointUnresolved('The push service host name does not resolve.');
        }

        // Every address must be public, so a name cannot mix a public
        // address with an internal one.
        foreach ($addresses as $address) {
            if (! $private && ! self::isPublic($address)) {
                throw new PushEndpointRefused('The push service is on a private or reserved network.');
            }
        }

        return new PushEndpoint($scheme, $host, $port, $addresses[0]);
    }

    public static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);

        if (! is_string($packed)) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! self::inAny($packed, self::IPV4_BLOCKED);
        }

        $embedded = self::embeddedIpv4($packed);

        if ($embedded !== null) {
            return ! self::inAny($embedded, self::IPV4_BLOCKED);
        }

        return ! self::inAny($packed, self::IPV6_BLOCKED);
    }

    /**
     * The IPv4 address inside an IPv4-mapped (::ffff:0:0/96), NAT64
     * (64:ff9b::/96, 64:ff9b:1::/48) or 6to4 (2002::/16) address.
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        if (self::in($packed, '::ffff:0:0/96') || self::in($packed, '64:ff9b::/96') || self::in($packed, '64:ff9b:1::/48')) {
            return substr($packed, 12, 4);
        }

        if (self::in($packed, '2002::/16')) {
            return substr($packed, 2, 4);
        }

        return null;
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::in($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function in(string $packed, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $base = (string) inet_pton($network);

        if (strlen($base) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}

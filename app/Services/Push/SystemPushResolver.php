<?php

namespace App\Services\Push;

/**
 * The system resolver: IPv4 through the C library (so /etc/hosts counts),
 * IPv6 from DNS.
 */
final class SystemPushResolver implements PushResolver
{
    public function addresses(string $host): array
    {
        $addresses = [];
        $ipv4 = gethostbynamel($host);

        foreach (is_array($ipv4) ? $ipv4 : [] as $address) {
            $addresses[] = $address;
        }

        $records = @dns_get_record($host, DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}

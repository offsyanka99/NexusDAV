<?php

namespace App\Services\Push;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The PSR-18 client the Web Push library sends through, for one checked
 * endpoint (PushEndpointGuard). It connects only to the checked address
 * (CURLOPT_RESOLVE), never follows a redirect, uses no proxy, has short
 * timeouts, and reads at most 64 KB of the answer and only its status.
 */
final class PushHttpClient implements ClientInterface
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_BODY_BYTES = 65536;

    public function __construct(private PushEndpoint $endpoint) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        if ($scheme !== $this->endpoint->scheme || strtolower($uri->getHost()) !== $this->endpoint->host || $port !== $this->endpoint->port) {
            throw new PushNetworkError($request, 'The request is not for the checked push service.');
        }

        $headers = ['Expect:'];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name.': '.implode(', ', $values);
        }

        $status = 0;
        $reason = '';
        $body = '';
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => (string) $uri,
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_POSTFIELDS => (string) $request->getBody(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => [$this->endpoint->resolveEntry()],
            CURLOPT_PROTOCOLS => $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$reason): int {
                // The last status line wins (after 100 Continue). The
                // library reads only the status, so headers are not kept.
                if (preg_match('#^HTTP/\S+\s+(\d{3})\s*(.*)$#', trim($line), $match) === 1) {
                    $status = (int) $match[1];
                    $reason = trim((string) preg_replace('/[^\x20-\x7E]+/', ' ', $match[2]));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                $room = self::MAX_BODY_BYTES - strlen($body);

                if ($room <= 0) {
                    return 0;
                }

                $body .= substr($chunk, 0, $room);

                // Returning less than the chunk stops the transfer.
                return strlen($chunk) <= $room ? strlen($chunk) : 0;
            },
        ]);

        curl_exec($curl);
        $error = curl_errno($curl);

        // A body cut at the limit still has its status.
        if ($error !== 0 && ! ($error === CURLE_WRITE_ERROR && $status > 0)) {
            throw new PushNetworkError($request, curl_strerror($error) ?? 'The push service could not be reached.');
        }

        return new Response($status, [], $body, '1.1', $reason === '' ? null : $reason);
    }
}

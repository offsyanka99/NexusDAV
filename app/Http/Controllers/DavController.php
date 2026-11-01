<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequestLog;
use App\Services\AuthThrottle;
use App\Services\Dav\DavServerFactory;
use App\Services\DiagnosticEventWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\HTTP\Request as SabreRequest;
use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;
use Sabre\Uri;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DavController extends Controller
{
    public function __invoke(
        Request $request,
        DavServerFactory $factory,
        DiagnosticEventWriter $events,
        AuthThrottle $throttle,
    ): Response {
        $server = $factory->make();
        $server->httpRequest = $this->sabreRequest($request);
        $server->sapi = new class extends Sapi
        {
            public static function sendResponse(ResponseInterface $response) {}
        };
        $server->start();
        $this->restoreChallenge($server);

        $status = $server->httpResponse->getStatus();
        $username = $this->username($server);
        $request->attributes->set(RequestLog::USER_ATTRIBUTE, $username);
        $this->recordError($events, $throttle, $request, $status, $username);

        return $this->laravelResponse($server);
    }

    /**
     * Keeps 4xx and 5xx answers for the Diagnostics page. A 401 is already
     * stored with its reason by the auth backend. Recording must never change
     * the answer the client gets.
     */
    private function recordError(DiagnosticEventWriter $events, AuthThrottle $throttle, Request $request, int $status, ?string $username): void
    {
        if ($status < 400 || $status > 599 || $status === 401) {
            return;
        }

        try {
            $events->recordHttpError($throttle->clientIp(), $username ?? '', $request->getMethod(), $request->getPathInfo(), $status);
        } catch (Throwable $exception) {
            Log::warning('Could not record a DAV error for Diagnostics', ['exception' => $exception]);
        }
    }

    private function sabreRequest(Request $request): SabreRequest
    {
        $sabre = new SabreRequest(
            $request->getMethod(),
            $request->getRequestUri(),
            $request->headers->all(),
            $request->getContent(true),
        );
        $sabre->setAbsoluteUrl($request->getSchemeAndHttpHost().$request->getRequestUri());
        $version = $request->getProtocolVersion();

        if ($version !== '') {
            $sabre->setHttpVersion($version);
        }

        return $sabre;
    }

    /**
     * sabre replaces the response headers when it renders an exception, which
     * drops the Basic challenge the auth plugin already set.
     */
    private function restoreChallenge(Server $server): void
    {
        $response = $server->httpResponse;

        if ($response->getStatus() !== 401 || $response->hasHeader('WWW-Authenticate')) {
            return;
        }

        $auth = $server->getPlugin('auth');

        if ($auth instanceof AuthPlugin) {
            $auth->challenge($server->httpRequest, $response);
        }
    }

    private function username(Server $server): ?string
    {
        $auth = $server->getPlugin('auth');

        if (! $auth instanceof AuthPlugin) {
            return null;
        }

        $principal = $auth->getCurrentPrincipal();

        if (! is_string($principal) || $principal === '') {
            return null;
        }

        $name = Uri\split($principal)[1] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function laravelResponse(Server $server): Response
    {
        $sabre = $server->httpResponse;
        $status = $sabre->getStatus();
        $headers = $sabre->getHeaders();
        $body = $sabre->getBody();

        if (is_string($body)) {
            return response($body, $status, $headers);
        }

        if (is_resource($body)) {
            return response()->stream(function () use ($body): void {
                try {
                    fpassthru($body);
                } finally {
                    fclose($body);
                }
            }, $status, $headers);
        }

        if (is_callable($body)) {
            return response()->stream(function () use ($body): void {
                $body();
            }, $status, $headers);
        }

        return $this->blankResponse($status, $headers);
    }

    /**
     * Sabre left the body empty and set no Content-Type. Symfony's prepare()
     * would otherwise invent text/html, and the SAPI would add it back from
     * default_mimetype when the header is absent.
     *
     * @param  array<string, array<int, string|null>|string>  $headers
     */
    private function blankResponse(int $status, array $headers): Response
    {
        return new class($status, $headers) extends \Illuminate\Http\Response
        {
            /**
             * @param  array<string, array<int, string|null>|string>  $headers
             */
            public function __construct(int $status, array $headers)
            {
                parent::__construct('', $status, $headers);
            }

            public function prepare(SymfonyRequest $request): static
            {
                $keepType = $this->headers->has('Content-Type');
                parent::prepare($request);

                if (! $keepType) {
                    $this->headers->remove('Content-Type');
                    ini_set('default_mimetype', '');
                }

                return $this;
            }
        };
    }
}

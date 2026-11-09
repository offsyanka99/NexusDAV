<?php

namespace App\Http\Controllers;

use App\Events\DavServerCreated;
use App\Http\Middleware\RequestLog;
use App\Services\AuthThrottle;
use App\Services\Dav\BusyDatabase;
use App\Services\Dav\DavServerFactory;
use App\Services\Dav\DavWriteLock;
use App\Services\DiagnosticEventWriter;
use App\Services\Push\PushChanges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Server;
use Sabre\HTTP\Request as SabreRequest;
use Sabre\HTTP\Response as SabreResponse;
use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;
use Sabre\Uri;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DavController extends Controller
{
    /**
     * Retries after a busy database, on top of the first attempt.
     */
    public const RETRIES = 3;

    public const RETRY_AFTER_SECONDS = 5;

    public function __invoke(
        Request $request,
        DavServerFactory $factory,
        DiagnosticEventWriter $events,
        AuthThrottle $throttle,
    ): Response {
        if ($this->outsideBase($request)) {
            // sabre would throw a LogicException and answer 500. The path
            // resolves to something outside /dav/, which is not served.
            $this->recordError($events, $throttle, $request, 404, null);
            $missing = $this->errorResponse(404, 'Not found.');

            return response($missing->getBodyAsString(), 404, $missing->getHeaders());
        }

        // Kept as a string so a write can be replayed. CalDAV and CardDAV
        // bodies are small. A write to a file home is not replayed: its disk
        // changes are not rolled back with the transaction. Its upload is
        // streamed from the request, so a large file never sits in memory,
        // and a long upload or copy is not cut off by max_execution_time.
        $files = DavServerFactory::underFiles(substr(rawurldecode('/'.$request->path()), strlen('/dav')));
        $write = in_array(strtoupper($request->getMethod()), DavWriteLock::METHODS, true);
        $replayable = $write && ! $files;

        if ($files && $write) {
            @set_time_limit(0);
        }

        $body = $files && strtoupper($request->getMethod()) === 'PUT' ? $request->getContent(true) : $request->getContent();

        for ($attempt = 1; ; $attempt++) {
            [$server, $failure] = $this->attempt($factory, $request, $body);

            if ($failure === null || ! BusyDatabase::caused($failure)) {
                break;
            }

            if ($replayable && $attempt <= self::RETRIES) {
                Log::warning('Database busy, retrying the DAV write', ['attempt' => $attempt, 'error' => $failure->getMessage()]);
                usleep(random_int(50_000, 250_000) * $attempt);

                continue;
            }

            Log::error('Database busy, answered 503', ['attempts' => $attempt, 'exception' => $failure]);
            $server->httpResponse = $this->errorResponse(503, 'The server is busy. Try again shortly.', ['Retry-After' => (string) self::RETRY_AFTER_SECONDS]);
            $failure = null;

            break;
        }

        if ($failure !== null && ! $failure instanceof DavException && $server->httpResponse->getStatus() === 500) {
            // sabre puts the exception text in the body: SQL, file paths.
            // The details are in the log under the request ID.
            $server->httpResponse = $this->errorResponse(500, 'Internal server error.');
        }

        $this->restoreChallenge($server);

        $status = $server->httpResponse->getStatus();
        $username = $this->username($server);
        $request->attributes->set(RequestLog::USER_ATTRIBUTE, $username);
        $this->recordError($events, $throttle, $request, $status, $username);

        return $this->laravelResponse($server);
    }

    /**
     * One run of sabre with its own write transaction. Returns the server and
     * the exception sabre answered, if any.
     *
     * @param  string|resource  $body
     * @return array{0: Server, 1: Throwable|null}
     */
    private function attempt(DavServerFactory $factory, Request $request, mixed $body): array
    {
        $server = $factory->make();
        $writeLock = new DavWriteLock;
        $writeLock->attach($server);
        event(new DavServerCreated($server));

        $failure = null;
        $server->on('exception', function (Throwable $exception) use (&$failure): void {
            $failure = $exception;
        });

        $server->httpRequest = $this->sabreRequest($request, $body);
        $server->sapi = new class extends Sapi
        {
            public static function sendResponse(ResponseInterface $response) {}
        };

        try {
            $server->start();
        } catch (Throwable $exception) {
            // sabre answers every exception itself; this covers one that
            // escaped it, so the transaction never outlives the request.
            $writeLock->abort();
            app(PushChanges::class)->reset();

            throw $exception;
        }

        try {
            $writeLock->finish();
        } catch (Throwable $exception) {
            if (! BusyDatabase::caused($exception)) {
                throw $exception;
            }

            // The answer sabre built was never committed.
            $failure = $exception;
        } finally {
            // Push-Dont-Notify belongs to this request only.
            app(PushChanges::class)->reset();
        }

        return [$server, $failure];
    }

    /**
     * The same test as sabre's Request::getPath(), on the path without its
     * query: after dot segments are resolved, it must still be under /dav/.
     */
    private function outsideBase(Request $request): bool
    {
        $path = explode('?', $request->getRequestUri(), 2)[0];
        $uri = Uri\normalize(str_replace('//', '/', $path));

        return ! str_starts_with($uri, '/dav/') && $uri !== '/dav';
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function errorResponse(int $status, string $message, array $headers = []): SabreResponse
    {
        $id = RequestLog::id();
        $text = htmlspecialchars($id === null ? $message : $message.' Request ID: '.$id, ENT_XML1, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">'."\n"
            .'  <s:message>'.$text.'</s:message>'."\n"
            .'</d:error>'."\n";

        $response = new SabreResponse($status, ['Content-Type' => 'application/xml; charset=utf-8'] + $headers);
        $response->setBody($xml);

        return $response;
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

    /**
     * @param  string|resource  $body
     */
    private function sabreRequest(Request $request, mixed $body): SabreRequest
    {
        $sabre = new SabreRequest(
            $request->getMethod(),
            $request->getRequestUri(),
            $request->headers->all(),
            $body,
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
            // A range answer leaves the stream at the range start and relies
            // on the SAPI to stop after Content-Length bytes, as sabre's does.
            $length = $sabre->getHeader('Content-Length');
            $limit = is_string($length) && ctype_digit($length) ? (int) $length : null;

            return response()->stream(function () use ($body, $limit): void {
                try {
                    $this->sendStream($body, $limit);
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
     * One chunk at a time. fpassthru() and stream_copy_to_stream() map a
     * file into memory and hand it to the output layer whole, which for a
     * large file ends PHP at its memory limit and stops FrankenPHP with it.
     *
     * @param  resource  $body
     */
    private function sendStream($body, ?int $limit): void
    {
        $left = $limit ?? PHP_INT_MAX;

        while ($left > 0 && ! feof($body)) {
            $chunk = fread($body, (int) min(1048576, $left));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
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

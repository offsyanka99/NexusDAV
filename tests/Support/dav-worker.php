<?php

/**
 * One DAV request from a separate PHP process, for ConcurrentWritesTest.
 *
 * Argument: JSON with method, uri, user, password, headers, body, start (a
 * unix time in microseconds to wait for, so several workers send together)
 * and hold_ms (a pause just before sabre writes, after the precondition
 * check, so concurrent requests overlap in that window). Prints the status
 * ETag and Retry-After as JSON.
 */

use App\Events\DavServerCreated;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';

$job = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$hold = (int) ($job['hold_ms'] ?? 0);

if ($hold > 0) {
    Event::listen(DavServerCreated::class, function (DavServerCreated $event) use ($hold): void {
        foreach (['PUT', 'DELETE'] as $method) {
            // sabre's core handler has the default priority, 100.
            $event->server->on('method:'.$method, function () use ($hold): void {
                usleep($hold * 1000);
            }, 90);
        }
    });
}

$server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($job['user'].':'.$job['password'])];

foreach ($job['headers'] ?? [] as $name => $value) {
    $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}

$request = Request::create($job['uri'], $job['method'], [], [], [], $server, $job['body'] ?? null);

while (($wait = (int) $job['start'] - (int) (microtime(true) * 1_000_000)) > 0) {
    usleep(min($wait, 10_000));
}

$response = $kernel->handle($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'etag' => $response->headers->get('ETag'),
    'retry_after' => $response->headers->get('Retry-After'),
]);

$kernel->terminate($request, $response);

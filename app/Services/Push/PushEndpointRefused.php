<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * The SSRF guard refused a push resource: not https, or an address on a
 * private, loopback, link-local, multicast or reserved network. Such a
 * subscription is removed at once. The message never has the URL.
 */
final class PushEndpointRefused extends RuntimeException {}

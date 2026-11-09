<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * The push service's host name did not resolve. Like a connection error,
 * this is retried: DNS may be down for a moment.
 */
final class PushEndpointUnresolved extends RuntimeException {}

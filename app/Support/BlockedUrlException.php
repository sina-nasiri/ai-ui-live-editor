<?php

namespace App\Support;

use RuntimeException;

/**
 * Thrown when a URL is rejected before or during an outbound fetch.
 *
 * The message is safe to show to the user: it never contains resolved IPs or
 * anything else that would turn the editor into a network scanner.
 */
class BlockedUrlException extends RuntimeException {}

<?php

namespace App\Services\Channex;

use RuntimeException;

/**
 * Channex refused a request or could not be reached; the message is safe to show staff.
 */
class ChannexException extends RuntimeException {}

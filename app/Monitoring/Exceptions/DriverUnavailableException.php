<?php

declare(strict_types=1);

namespace App\Monitoring\Exceptions;

use RuntimeException;

/** The configured monitoring driver does not exist or cannot be used. */
class DriverUnavailableException extends RuntimeException {}
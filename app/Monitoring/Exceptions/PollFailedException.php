<?php

declare(strict_types=1);

namespace App\Monitoring\Exceptions;

use RuntimeException;

/** The device could not be read. The message is shown to administrators, so it must not contain secrets. */
final class PollFailedException extends RuntimeException {}
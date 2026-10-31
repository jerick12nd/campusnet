<?php

declare(strict_types=1);

namespace App\Monitoring\Exceptions;

use RuntimeException;

/** An SNMP request failed. The message is safe to show to administrators. */
class SnmpException extends RuntimeException {}
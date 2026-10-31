<?php

declare(strict_types=1);

namespace App\Monitoring\Snmp;

use App\Enums\SnmpVersion;
use App\Monitoring\Exceptions\SnmpException;

/**
 * Thin, read-only wrapper around the PHP SNMP extension (GET requests only).
 *
 * Credentials are used to build the request and are never logged or put in
 * exception messages: failures are translated into fixed, safe sentences.
 */
final class SnmpClient
{
    public const OID_SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    public const OID_SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    public const OID_SYS_NAME = '1.3.6.1.2.1.1.5.0';

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly SnmpVersion $version,
        private readonly array $credentials,
        private readonly int $timeoutSeconds = 5,
        private readonly int $retries = 1,
    ) {}

    public static function isAvailable(): bool
    {
        return extension_loaded('snmp') && function_exists('snmp2_get') && function_exists('snmp3_get');
    }

    /**
     * Fetch one scalar OID.
     *
     * @throws SnmpException
     */
    public function get(string $oid): string
    {
        $timeout = max(1, $this->timeoutSeconds) * 1_000_000; // the extension expects microseconds
        $retries = max(0, $this->retries);
        $target = $this->target();

        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $value = match ($this->version) {
                SnmpVersion::V2c => snmp2_get(
                    $target,
                    (string) ($this->credentials['community'] ?? ''),
                    $oid,
                    $timeout,
                    $retries,
                ),
                SnmpVersion::V3 => snmp3_get(
                    $target,
                    (string) ($this->credentials['username'] ?? ''),
                    (string) ($this->credentials['security_level'] ?? 'authPriv'),
                    (string) ($this->credentials['auth_protocol'] ?? 'SHA'),
                    (string) ($this->credentials['auth_password'] ?? ''),
                    (string) ($this->credentials['priv_protocol'] ?? ''),
                    (string) ($this->credentials['priv_password'] ?? ''),
                    $oid,
                    $timeout,
                    $retries,
                ),
            };
        } finally {
            restore_error_handler();
        }

        if ($value === false) {
            throw new SnmpException(self::describeFailure($warning));
        }

        return trim((string) $value);
    }

    /**
     * Turn a raw PHP/net-snmp warning into a fixed sentence. The raw text is discarded.
     */
    public static function describeFailure(?string $rawWarning): string
    {
        $raw = strtolower((string) $rawWarning);

        return match (true) {
            str_contains($raw, 'no response') || str_contains($raw, 'timeout') => 'No response from the device (timeout). Check the IP address, SNMP port, firewall rules and that SNMP is enabled on the device. With SNMPv2c a wrong community string also looks like a timeout.',
            str_contains($raw, 'authentication')
                || str_contains($raw, 'unknown user')
                || str_contains($raw, 'decrypt')
                || str_contains($raw, 'security level')
                || str_contains($raw, 'engine') => 'SNMP authentication failed. Check the SNMPv3 user name, security level, protocols and passphrases.',
            str_contains($raw, 'unknown host') || str_contains($raw, 'name or service not known') => 'The device address could not be resolved.',
            default => 'The SNMP request failed. Check the device address, port and credentials.',
        };
    }

    private function target(): string
    {
        return str_contains($this->host, ':')
            ? "[{$this->host}]:{$this->port}"
            : "{$this->host}:{$this->port}";
    }
}
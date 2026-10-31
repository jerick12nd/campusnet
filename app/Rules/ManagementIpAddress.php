<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A device management address must be a unicast IPv4 or IPv6 address.
 *
 * Loopback, "this network", link-local (which includes 169.254.169.254, the cloud
 * metadata address), multicast, broadcast and reserved ranges are rejected so the
 * connection test can never be pointed at the server itself or a metadata service.
 * Private ranges (10/8, 172.16/12, 192.168/16) are allowed: they are what campus
 * management networks use.
 */
final class ManagementIpAddress implements ValidationRule
{
    public static function isAllowed(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $octets = array_values(unpack('C4', $packed));
            [$a, $b] = $octets;

            if ($a === 0 || $a === 127 || $a >= 224) {
                return false; // this network, loopback, multicast, broadcast / reserved
            }

            return ! ($a === 169 && $b === 254); // link-local
        }

        if ($packed === inet_pton('::') || $packed === inet_pton('::1')) {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        if ($first === 0xFF) {
            return false; // multicast
        }

        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false; // fe80::/10 link-local
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d): judge the embedded IPv4 address.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $embedded = inet_ntop(substr($packed, 12));

            return $embedded !== false && self::isAllowed($embedded);
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAllowed(trim($value))) {
            $fail('The :attribute must be a valid unicast IPv4 or IPv6 address (loopback, link-local and multicast addresses are not allowed).');
        }
    }
}
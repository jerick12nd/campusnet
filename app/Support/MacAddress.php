<?php

declare(strict_types=1);

namespace App\Support;

final class MacAddress
{
    /** Returns AA:BB:CC:DD:EE:FF, or null when the input is not a valid MAC. */
    public static function normalize(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /**
     * Phones often use a private (randomized) Wi-Fi address that changes per network.
     * Those are "locally administered" addresses: bit 2 of the first byte is set.
     * A registered randomized MAC may stop matching, so the UI should warn about it.
     */
    public static function isLocallyAdministered(string $mac): bool
    {
        $normalized = self::normalize($mac);

        if ($normalized === null) {
            return false;
        }

        return (hexdec(substr($normalized, 0, 2)) & 0x02) === 0x02;
    }
}
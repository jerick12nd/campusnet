<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cleans the names that DNS and proxy logs report. Only plain domain names are kept:
 * no IP addresses, no single-label host names, no local or reverse-lookup names.
 */
final class DomainName
{
    private const LOCAL_SUFFIXES = ['local', 'lan', 'localdomain', 'localhost', 'home', 'internal', 'intranet', 'arpa', 'invalid'];

    /** Second-level labels used under two-letter country codes, e.g. dssc.edu.ph or bbc.co.uk. */
    private const SECOND_LEVEL = ['com', 'net', 'org', 'edu', 'gov', 'co', 'ac', 'mil', 'sch', 'or', 'ne', 'go'];

    /** Returns the lower-case name, or null when it is not a usable domain name. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $domain = rtrim(strtolower(trim($value)), '.');

        if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $domain);

        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63 || preg_match('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?$/', $label) !== 1) {
                return null;
            }
        }

        $tld = end($labels);

        if (ctype_digit($tld) || in_array($tld, self::LOCAL_SUFFIXES, true)) {
            return null;
        }

        return $domain;
    }

    /**
     * The name a person would recognise: www.google.com becomes google.com.
     * This is a simple rule, not the full public suffix list, so unusual suffixes may group too widely.
     */
    public static function base(string $domain): string
    {
        $labels = explode('.', $domain);
        $count = count($labels);

        if ($count <= 2) {
            return $domain;
        }

        $tld = $labels[$count - 1];
        $second = $labels[$count - 2];
        $take = (strlen($tld) === 2 && in_array($second, self::SECOND_LEVEL, true)) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }
}
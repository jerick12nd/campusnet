<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\ManagementIpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManagementIpAddressTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function allowed(): array
    {
        return [
            'class A private' => ['10.0.0.1'],
            'class B private' => ['172.16.5.5'],
            'class C private' => ['192.168.1.1'],
            'public address' => ['203.0.113.7'],
            'just below multicast' => ['223.255.255.254'],
            'next to link-local' => ['169.253.1.1'],
            'ipv6 documentation range' => ['2001:db8::1'],
            'ipv6 unique local' => ['fd00::10'],
            'ipv4-mapped private' => ['::ffff:10.0.0.1'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback range' => ['127.45.0.9'],
            'this network' => ['0.0.0.0'],
            'cloud metadata' => ['169.254.169.254'],
            'link-local' => ['169.254.1.1'],
            'multicast' => ['224.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'reserved' => ['240.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped metadata' => ['::ffff:169.254.169.254'],
            'hostname' => ['router.local'],
            'not an address' => ['not-an-ip'],
            'out of range octet' => ['256.1.1.1'],
            'too few octets' => ['10.0.1'],
            'with a port' => ['10.0.0.1:161'],
            'empty' => [''],
        ];
    }

    #[DataProvider('allowed')]
    public function test_unicast_addresses_are_allowed(string $ip): void
    {
        $this->assertTrue(ManagementIpAddress::isAllowed($ip), $ip);
    }

    #[DataProvider('refused')]
    public function test_unsafe_or_invalid_addresses_are_refused(string $ip): void
    {
        $this->assertFalse(ManagementIpAddress::isAllowed($ip), $ip);
    }

    public function test_the_rule_reports_a_failure_for_an_unsafe_address(): void
    {
        $message = null;

        (new ManagementIpAddress)->validate('management_ip', '127.0.0.1', function (string $m) use (&$message): void {
            $message = $m;
        });

        $this->assertNotNull($message);
        $this->assertStringContainsString('loopback', $message);
    }

    public function test_the_rule_trims_surrounding_whitespace_and_rejects_non_strings(): void
    {
        $failures = 0;
        $fail = function () use (&$failures): void {
            $failures++;
        };

        (new ManagementIpAddress)->validate('management_ip', '  10.0.0.1  ', $fail);
        $this->assertSame(0, $failures);

        (new ManagementIpAddress)->validate('management_ip', ['10.0.0.1'], $fail);
        (new ManagementIpAddress)->validate('management_ip', null, $fail);
        (new ManagementIpAddress)->validate('management_ip', 167772161, $fail);
        $this->assertSame(3, $failures);
    }
}
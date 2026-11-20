<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainNameTest extends TestCase
{
    #[DataProvider('validNames')]
    public function test_usable_names_are_normalised(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainName::normalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function validNames(): array
    {
        return [
            'upper case' => ['Google.COM', 'google.com'],
            'trailing dot' => ['www.youtube.com.', 'www.youtube.com'],
            'whitespace' => ['  github.com ', 'github.com'],
            'service label' => ['_dmarc.example.org', '_dmarc.example.org'],
            'punycode' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
        ];
    }

    #[DataProvider('unusableNames')]
    public function test_unusable_names_are_refused(string $input): void
    {
        $this->assertNull(DomainName::normalize($input));
    }

    /** @return array<string, array{string}> */
    public static function unusableNames(): array
    {
        return [
            'empty' => [''],
            'single label' => ['router1'],
            'ipv4' => ['192.168.1.10'],
            'ipv6' => ['2001:db8::1'],
            'reverse lookup' => ['10.1.168.192.in-addr.arpa'],
            'local suffix' => ['printer.local'],
            'lan suffix' => ['nas.lan'],
            'space' => ['bad domain.com'],
            'empty label' => ['a..b.com'],
            'long label' => [str_repeat('a', 64).'.com'],
            'too long' => [str_repeat('a.', 130).'com'],
            'numeric tld' => ['host.123'],
            'leading hyphen' => ['-bad.com'],
        ];
    }

    #[DataProvider('baseNames')]
    public function test_the_base_domain_is_the_recognisable_part(string $domain, string $expected): void
    {
        $this->assertSame($expected, DomainName::base($domain));
    }

    /** @return array<string, array{string, string}> */
    public static function baseNames(): array
    {
        return [
            'www prefix' => ['www.google.com', 'google.com'],
            'already base' => ['google.com', 'google.com'],
            'many labels' => ['rr3---sn-abc.googlevideo.com', 'googlevideo.com'],
            'philippine edu' => ['www.dssc.edu.ph', 'dssc.edu.ph'],
            'philippine gov' => ['mail.deped.gov.ph', 'deped.gov.ph'],
            'philippine gov base' => ['deped.gov.ph', 'deped.gov.ph'],
            'uk company' => ['news.bbc.co.uk', 'bbc.co.uk'],
            'github pages' => ['a.b.github.io', 'github.io'],
        ];
    }
}
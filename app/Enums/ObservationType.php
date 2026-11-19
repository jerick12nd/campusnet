<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of telemetry produced a domain record. The UI must show this label,
 * because a DNS lookup does not prove that a website was actually visited.
 */
enum ObservationType: string
{
    case DnsQuery = 'dns_query';
    case ProxyRequest = 'proxy_request';
    case FirewallConnection = 'firewall_connection';

    public function label(): string
    {
        return match ($this) {
            self::DnsQuery => 'DNS lookup',
            self::ProxyRequest => 'Proxy request',
            self::FirewallConnection => 'Firewall connection',
        };
    }
}
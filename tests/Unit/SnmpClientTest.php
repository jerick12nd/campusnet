<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Monitoring\Snmp\SnmpClient;
use PHPUnit\Framework\TestCase;

class SnmpClientTest extends TestCase
{
    public function test_timeouts_get_a_firewall_and_community_hint(): void
    {
        foreach (['snmp2_get(): No response from 10.0.0.1:161', 'Timeout: No Response from 10.0.0.1', 'request timeout'] as $raw) {
            $message = SnmpClient::describeFailure($raw);

            $this->assertStringContainsString('No response from the device (timeout)', $message, $raw);
            $this->assertStringContainsString('community', $message);
        }
    }

    public function test_authentication_problems_are_named_as_such(): void
    {
        foreach ([
            'Authentication failure (incorrect password, community or key)',
            'Unknown user name',
            'snmp3_get(): Error in packet: Decryption error',
            'Unsupported security level',
            'Unknown engine ID',
        ] as $raw) {
            $this->assertStringContainsString('SNMP authentication failed', SnmpClient::describeFailure($raw), $raw);
        }
    }

    public function test_unresolvable_hosts_are_named_as_such(): void
    {
        $this->assertSame(
            'The device address could not be resolved.',
            SnmpClient::describeFailure('php_network_getaddresses: getaddrinfo failed: Name or service not known'),
        );
        $this->assertSame('The device address could not be resolved.', SnmpClient::describeFailure('Unknown host (bad.example)'));
    }

    public function test_anything_else_gets_a_generic_message(): void
    {
        $generic = 'The SNMP request failed. Check the device address, port and credentials.';

        $this->assertSame($generic, SnmpClient::describeFailure('something unexpected'));
        $this->assertSame($generic, SnmpClient::describeFailure(''));
        $this->assertSame($generic, SnmpClient::describeFailure(null));
    }

    public function test_the_raw_warning_is_never_echoed(): void
    {
        $raw = 'snmp3_get(): No response from 10.0.0.1:161 user=campusnet-ro auth=hunter2-auth priv=hunter2-priv community=hunter2';

        $message = SnmpClient::describeFailure($raw);

        foreach (['hunter2', 'campusnet-ro', '10.0.0.1'] as $leak) {
            $this->assertStringNotContainsString($leak, $message);
        }
    }

    public function test_the_standard_object_ids_are_the_system_group_scalars(): void
    {
        $this->assertSame('1.3.6.1.2.1.1.1.0', SnmpClient::OID_SYS_DESCR);
        $this->assertSame('1.3.6.1.2.1.1.3.0', SnmpClient::OID_SYS_UPTIME);
        $this->assertSame('1.3.6.1.2.1.1.5.0', SnmpClient::OID_SYS_NAME);
    }
}
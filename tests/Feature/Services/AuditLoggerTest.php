<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_looking_metadata_keys_are_redacted_at_any_depth(): void
    {
        $log = app(AuditLogger::class)->record('device.updated', null, [
            'name' => 'Core Router',
            'snmp_community' => 'public-secret',
            'password' => 'hunter2',
            'credentials' => ['auth_key' => 'abc', 'user' => 'monitor'],
            'api_token' => 'tok_123',
            'nested' => ['priv_passphrase' => 'xyz', 'port' => 161],
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);

        $metadata = $log->fresh()->metadata;

        $this->assertSame('Core Router', $metadata['name']);
        $this->assertSame(AuditLogger::REDACTED, $metadata['snmp_community']);
        $this->assertSame(AuditLogger::REDACTED, $metadata['password']);
        $this->assertSame(AuditLogger::REDACTED, $metadata['credentials']);
        $this->assertSame(AuditLogger::REDACTED, $metadata['api_token']);
        $this->assertSame(AuditLogger::REDACTED, $metadata['nested']['priv_passphrase']);
        $this->assertSame(161, $metadata['nested']['port']);

        $raw = json_encode($log->fresh()->toArray());
        foreach (['public-secret', 'hunter2', 'tok_123', 'xyz'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_actor_and_request_details_are_captured(): void
    {
        $log = app(AuditLogger::class)->record('settings.updated', null, [], 'Thresholds changed.');

        $this->assertSame('settings.updated', $log->action);
        $this->assertSame('Thresholds changed.', $log->description);
        $this->assertNotNull($log->created_at);
    }
}
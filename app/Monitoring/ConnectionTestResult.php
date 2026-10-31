<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\DeviceStatus;

/**
 * Outcome of testing one configured device.
 *
 * `status` is what the test learned about the device. It is null when the test could
 * not be performed at all (extension missing, no credentials, simulated driver): in
 * that case nothing is known about the device, so its stored status must not change.
 */
final readonly class ConnectionTestResult
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public bool $success,
        public ?DeviceStatus $status,
        public string $message,
        public ?int $latencyMs = null,
        public array $details = [],
        public bool $simulated = false,
    ) {}

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function reached(string $message, int $latencyMs, array $details = []): self
    {
        return new self(true, DeviceStatus::Online, $message, $latencyMs, $details);
    }

    /** The device was contacted (or could not be contacted) and monitoring is not possible. */
    public static function unreachable(string $message): self
    {
        return new self(false, DeviceStatus::Unreachable, $message);
    }

    /** A setup problem prevented the test. Says nothing about the device itself. */
    public static function notPerformed(string $message): self
    {
        return new self(false, null, $message);
    }

    /** Demo mode: no packet was sent. */
    public static function demo(string $message): self
    {
        return new self(true, null, $message, null, [], true);
    }

    public function shouldPersist(): bool
    {
        return ! $this->simulated && $this->status !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status?->value,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
            'details' => $this->details,
            'simulated' => $this->simulated,
        ];
    }
}
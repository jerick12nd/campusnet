<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertStatus;
use App\Models\NetworkAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a person does with an alert. Every change is written together with its audit record.
 */
final class AlertService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return bool false when the alert is not active any more (nothing was changed)
     */
    public function acknowledge(NetworkAlert $alert, User $actor): bool
    {
        if ($alert->status !== AlertStatus::Active) {
            return false;
        }

        DB::transaction(function () use ($alert, $actor): void {
            $alert->forceFill([
                'status' => AlertStatus::Acknowledged,
                'acknowledged_by' => $actor->id,
                'acknowledged_at' => now(),
            ])->save();

            $this->audit->record('alert.acknowledged', $alert, [
                'title' => $alert->title,
                'type' => $alert->type->value,
                'severity' => $alert->severity->value,
            ], "Alert acknowledged: {$alert->title}", $actor);
        });

        return true;
    }

    /**
     * @return bool false when the alert was already resolved (nothing was changed)
     */
    public function resolve(NetworkAlert $alert, User $actor, ?string $note): bool
    {
        if (! $alert->status->isOpen()) {
            return false;
        }

        $note = $note === null ? null : trim($note);

        DB::transaction(function () use ($alert, $actor, $note): void {
            $alert->forceFill([
                'status' => AlertStatus::Resolved,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $note === null || $note === '' ? null : Str::limit($note, 255, ''),
            ])->save();

            $this->audit->record('alert.resolved', $alert, [
                'title' => $alert->title,
                'type' => $alert->type->value,
                'severity' => $alert->severity->value,
                'note' => $alert->resolution_note,
            ], "Alert resolved: {$alert->title}", $actor);
        });

        return true;
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OfficeRequest;
use App\Models\Office;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(OfficeRequest $request): RedirectResponse
    {
        $office = Office::create($request->validated());

        $this->audit->record(
            action: 'office.created',
            subject: $office,
            description: "Office {$office->name} created.",
            metadata: $request->validated(),
            actor: $request->user(),
        );

        return back()->with('success', "Office {$office->name} added.");
    }

    public function update(OfficeRequest $request, Office $office): RedirectResponse
    {
        $office->fill($request->validated());
        $changes = $office->getDirty();

        if ($changes === []) {
            return back()->with('success', 'Nothing changed.');
        }

        $office->save();

        $this->audit->record(
            action: 'office.updated',
            subject: $office,
            description: "Office {$office->name} updated.",
            metadata: ['changed' => array_keys($changes)],
            actor: $request->user(),
        );

        return back()->with('success', "Office {$office->name} updated.");
    }

    /** Archives the office (soft delete). History is kept. */
    public function destroy(Request $request, Office $office): RedirectResponse
    {
        $active = $office->members()->where('is_active', true)->count()
            + $office->registeredDevices()->where('is_active', true)->count();

        if ($active > 0) {
            return back()->with('error', "{$office->name} still has active members or devices. Move or deactivate them first.");
        }

        $office->delete();

        $this->audit->record(
            action: 'office.archived',
            subject: $office,
            description: "Office {$office->name} archived.",
            actor: $request->user(),
        );

        return back()->with('success', "Office {$office->name} archived.");
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EndpointType;
use App\Http\Requests\AssignDeviceRequest;
use App\Http\Requests\RegisteredDeviceRequest;
use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\Office;
use App\Models\RegisteredDevice;
use App\Services\AuditLogger;
use App\Services\MemberAssignmentService;
use App\Support\MacAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredDeviceController extends Controller
{
    public function __construct(
        private readonly MemberAssignmentService $assignments,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(EndpointType::values())],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned'])],
            'state' => ['nullable', Rule::in(['active', 'inactive'])],
            // Set by the "Register" button on an unidentified connection.
            'register_mac' => ['nullable', 'string', 'max:50'],
            'register_name' => ['nullable', 'string', 'max:255'],
        ]);

        $canManage = $request->user()->can('assignments.manage');

        $devices = RegisteredDevice::query()
            ->with([
                'office' => fn ($q) => $q->withTrashed()->select('id', 'name'),
                'currentAssignment.member' => fn ($q) => $q->withTrashed()->select('id', 'full_name', 'office_id'),
                'currentAssignment.member.office' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            ])
            ->addSelect([
                'last_seen_at' => DeviceConnectionSession::query()
                    ->select('last_seen_at')
                    ->whereColumn('registered_device_id', 'registered_devices.id')
                    ->orderByDesc('last_seen_at')
                    ->limit(1),
            ])
            ->when($filters['search'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('mac_address', 'like', "%{$term}%")
                ->orWhereHas('currentAssignment.member', fn ($m) => $m->where('full_name', 'like', "%{$term}%"))))
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('device_type', $type))
            ->when($filters['assignment'] ?? null, fn ($q, string $assignment) => $assignment === 'assigned'
                ? $q->whereHas('currentAssignment')
                : $q->whereDoesntHave('currentAssignment'))
            ->when($filters['state'] ?? null, fn ($q, string $state) => $q->where('is_active', $state === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (RegisteredDevice $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'device_type' => $device->device_type?->value,
                'device_type_label' => $device->device_type?->label(),
                'mac_address' => $device->mac_address,
                'private_mac' => MacAddress::isLocallyAdministered($device->mac_address),
                'notes' => $device->notes,
                'office_id' => $device->office_id,
                'office_name' => $device->currentAssignment?->member?->office?->name ?? $device->office?->name,
                'member_id' => $device->currentAssignment?->member_id,
                'member_name' => $device->currentAssignment?->member?->full_name,
                'is_active' => $device->is_active,
                'last_seen_at' => $device->last_seen_at ? Carbon::parse($device->last_seen_at)->toIso8601String() : null,
            ]);

        $prefillMac = $canManage ? ($filters['register_mac'] ?? null) : null;

        return Inertia::render('registered-devices/index', [
            'devices' => $devices,
            'typeOptions' => collect(EndpointType::cases())
                ->map(fn (EndpointType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'offices' => $canManage
                ? Office::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'members' => $canManage
                ? Member::query()->where('is_active', true)
                    ->with(['office' => fn ($q) => $q->withTrashed()->select('id', 'name')])
                    ->orderBy('full_name')->get()
                    ->map(fn (Member $member) => [
                        'id' => $member->id,
                        'label' => $member->full_name.($member->office ? " - {$member->office->name}" : ''),
                    ])->values()
                : [],
            'prefill' => $prefillMac !== null
                ? ['mac' => $prefillMac, 'name' => $filters['register_name'] ?? '']
                : null,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'assignment' => $filters['assignment'] ?? '',
                'state' => $filters['state'] ?? '',
            ],
            'can' => ['manage' => $canManage],
        ]);
    }

    public function store(RegisteredDeviceRequest $request): RedirectResponse
    {
        $device = $this->assignments->register($request->validated(), $request->user());

        $note = MacAddress::isLocallyAdministered($device->mac_address)
            ? ' This looks like a private (randomized) Wi-Fi address. It can change per network, so the device may stop matching.'
            : '';

        return back()->with('success', "{$device->name} registered.{$note}");
    }

    public function update(RegisteredDeviceRequest $request, RegisteredDevice $registeredDevice): RedirectResponse
    {
        $registeredDevice->fill($request->validated());
        $changes = $registeredDevice->getDirty();

        if ($changes === []) {
            return back()->with('success', 'Nothing changed.');
        }

        $registeredDevice->save();

        // A device that is switched off cannot stay assigned to anyone.
        if (array_key_exists('is_active', $changes) && ! $registeredDevice->is_active) {
            $this->assignments->unassign($registeredDevice, $request->user());
        }

        $this->assignments->syncDevice($registeredDevice);

        $this->audit->record(
            action: 'device.updated',
            subject: $registeredDevice,
            description: "Registered device {$registeredDevice->name} updated.",
            metadata: ['changed' => array_keys($changes)],
            actor: $request->user(),
        );

        return back()->with('success', "{$registeredDevice->name} updated.");
    }

    public function assign(AssignDeviceRequest $request, RegisteredDevice $registeredDevice): RedirectResponse
    {
        if (! $registeredDevice->is_active) {
            return back()->with('error', 'Activate the device before assigning it to a member.');
        }

        $member = Member::query()->findOrFail($request->validated('member_id'));

        $this->assignments->assign($registeredDevice, $member, $request->user());

        return back()->with('success', "{$registeredDevice->name} assigned to {$member->full_name}.");
    }

    public function unassign(Request $request, RegisteredDevice $registeredDevice): RedirectResponse
    {
        $done = $this->assignments->unassign($registeredDevice, $request->user());

        return $done
            ? back()->with('success', "{$registeredDevice->name} unassigned.")
            : back()->with('error', "{$registeredDevice->name} is not assigned to anyone.");
    }
}
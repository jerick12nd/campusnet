<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\SnmpVersion;
use App\Http\Requests\Devices\IndexMonitoredDeviceRequest;
use App\Http\Requests\Devices\StoreMonitoredDeviceRequest;
use App\Http\Requests\Devices\UpdateMonitoredDeviceRequest;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Services\MonitoredDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoredDeviceController extends Controller
{
    public function index(IndexMonitoredDeviceRequest $request): Response
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        $devices = MonitoredDevice::query()
            ->withCount([
                'interfaces',
                'alerts as open_alerts_count' => fn ($query) => $query->open(),
            ])
            ->when($filters['search'] ?? null, function ($query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('management_ip', 'like', $like)
                        ->orWhere('vendor', 'like', $like)
                        ->orWhere('model', 'like', $like)
                        ->orWhere('location', 'like', $like);
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('last_status', $status))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('device_type', $type))
            ->when($filters['location'] ?? null, fn ($query, string $location) => $query->where('location', $location))
            ->when(($filters['enabled'] ?? null) === 'yes', fn ($query) => $query->where('is_enabled', true))
            ->when(($filters['enabled'] ?? null) === 'no', fn ($query) => $query->where('is_enabled', false))
            ->orderBy($sort, $direction) // $sort is whitelisted by IndexMonitoredDeviceRequest
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString()
            ->through(fn (MonitoredDevice $device): array => $this->summary($device));

        return Inertia::render('devices/index', [
            'devices' => $devices,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
                'location' => $filters['location'] ?? '',
                'enabled' => $filters['enabled'] ?? '',
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => (int) ($filters['per_page'] ?? 15),
            ],
            'locations' => MonitoredDevice::query()->whereNotNull('location')->distinct()->orderBy('location')->pluck('location'),
            'options' => $this->options(),
            'can' => $this->abilities($request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('devices/create', [
            'options' => $this->options(),
            'defaults' => [
                'device_type' => DeviceType::Router->value,
                'snmp_version' => SnmpVersion::V3->value,
                'snmp_port' => 161,
                'polling_interval_seconds' => 60,
            ],
        ]);
    }

    public function store(StoreMonitoredDeviceRequest $request, MonitoredDeviceService $service): RedirectResponse
    {
        $device = $service->create($request->deviceData(), $request->user());

        return to_route('devices.show', $device)
            ->with('success', "Device {$device->name} was added. Run a connection test to confirm it responds.");
    }

    public function show(Request $request, MonitoredDevice $device): Response
    {
        $device->loadCount([
            'interfaces',
            'alerts as open_alerts_count' => fn ($query) => $query->open(),
        ]);

        return Inertia::render('devices/show', [
            'device' => $this->detail($device),
            'interfaces' => $device->interfaces()->orderBy('if_index')->get()
                ->map(fn (NetworkInterface $interface): array => [
                    'id' => $interface->id,
                    'if_index' => $interface->if_index,
                    'name' => $interface->name,
                    'role' => ['value' => $interface->role->value, 'label' => $interface->role->label()],
                    'oper_status' => $interface->oper_status,
                    'is_monitored' => $interface->is_monitored,
                ])->all(),
            'alerts' => $device->alerts()->latest('triggered_at')->limit(5)->get()
                ->map(fn (NetworkAlert $alert): array => [
                    'id' => $alert->id,
                    'title' => $alert->title,
                    'severity' => $alert->severity->value,
                    'status' => $alert->status->value,
                    'triggered_at' => $alert->triggered_at->toIso8601String(),
                ])->all(),
            'can' => $this->abilities($request),
        ]);
    }

    public function edit(MonitoredDevice $device): Response
    {
        return Inertia::render('devices/edit', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'management_ip' => $device->management_ip,
                'vendor' => $device->vendor ?? '',
                'model' => $device->model ?? '',
                'device_type' => $device->device_type->value,
                'location' => $device->location ?? '',
                'description' => $device->description ?? '',
                'is_enabled' => $device->is_enabled,
                'snmp_version' => $device->snmp_version->value,
                'snmp_port' => $device->snmp_port,
                'polling_interval_seconds' => $device->polling_interval_seconds,
                // Only whether credentials exist, never what they are.
                'has_credentials' => $device->hasCredentials(),
            ],
            'options' => $this->options($device),
        ]);
    }

    public function update(UpdateMonitoredDeviceRequest $request, MonitoredDevice $device, MonitoredDeviceService $service): RedirectResponse
    {
        $service->update($device, $request->deviceData(), $request->user());

        return to_route('devices.show', $device)->with('success', "Device {$device->name} was updated.");
    }

    public function toggle(Request $request, MonitoredDevice $device, MonitoredDeviceService $service): RedirectResponse
    {
        $service->setEnabled($device, ! $device->is_enabled, $request->user());

        return back()->with('success', $device->is_enabled
            ? "Device {$device->name} is enabled and will be monitored."
            : "Device {$device->name} is disabled and will no longer be monitored.");
    }

    public function testConnection(Request $request, MonitoredDevice $device, MonitoredDeviceService $service): RedirectResponse
    {
        // Only the stored address is ever contacted; nothing from the request body is used.
        $result = $service->testConnection($device, $request->user());

        return back()->with('connection_test', [
            ...$result->toArray(),
            'device_id' => $device->id,
            'tested_at' => now()->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, MonitoredDevice $device, MonitoredDeviceService $service): RedirectResponse
    {
        $service->archive($device, $request->user());

        return to_route('devices.index')->with('success', "Device {$device->name} was archived. Its history is kept.");
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(MonitoredDevice $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'management_ip' => $device->management_ip,
            'vendor' => $device->vendor,
            'model' => $device->model,
            'device_type' => ['value' => $device->device_type->value, 'label' => $device->device_type->label()],
            'location' => $device->location,
            'is_enabled' => $device->is_enabled,
            'is_demo' => (bool) $device->is_demo,
            'last_status' => ['value' => $device->last_status->value, 'label' => $device->last_status->label()],
            'last_successful_poll_at' => $device->last_successful_poll_at?->toIso8601String(),
            'interfaces_count' => (int) ($device->interfaces_count ?? 0),
            'open_alerts_count' => (int) ($device->open_alerts_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(MonitoredDevice $device): array
    {
        return [
            ...$this->summary($device),
            'description' => $device->description,
            'snmp_version' => ['value' => $device->snmp_version->value, 'label' => $device->snmp_version->label()],
            'snmp_port' => $device->snmp_port,
            'polling_interval_seconds' => $device->polling_interval_seconds,
            'last_polled_at' => $device->last_polled_at?->toIso8601String(),
            'last_error' => $device->last_error,
            // Whether credentials exist is shown; their content never leaves the server.
            'has_credentials' => $device->hasCredentials(),
            'created_at' => $device->created_at?->toIso8601String(),
            'updated_at' => $device->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(?MonitoredDevice $device = null): array
    {
        $options = static fn (array $cases): array => array_values(array_map(
            static fn ($case): array => ['value' => $case->value, 'label' => $case->label()],
            $cases,
        ));

        // SNMPv2c is offered only when the server allows it (or the device being edited already uses it,
        // so the form can show its current value and the person can switch it to SNMPv3).
        $offerV2c = (bool) config('campusnet.allow_snmp_v2c', false) || $device?->snmp_version === SnmpVersion::V2c;

        return [
            'types' => $options(DeviceType::cases()),
            'versions' => $options(array_filter(
                SnmpVersion::cases(),
                static fn (SnmpVersion $version): bool => $offerV2c || $version !== SnmpVersion::V2c,
            )),
            'statuses' => $options(DeviceStatus::cases()),
        ];
    }

    /**
     * @return array{manage: bool}
     */
    private function abilities(Request $request): array
    {
        return ['manage' => (bool) $request->user()?->can('devices.manage')];
    }
}
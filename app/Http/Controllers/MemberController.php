<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MemberRequest;
use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\MemberDeviceAssignment;
use App\Models\Office;
use App\Services\AuditLogger;
use App\Services\ConnectedMemberService;
use App\Services\MemberAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __construct(
        private readonly MemberAssignmentService $assignments,
        private readonly ConnectedMemberService $connected,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'office' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $members = Member::query()
            ->with(['office' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->addSelect([
                'devices_count' => MemberDeviceAssignment::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('member_id', 'members.id')
                    ->whereNull('unassigned_at'),
                'last_seen_at' => DeviceConnectionSession::query()
                    ->select('last_seen_at')
                    ->whereColumn('member_id', 'members.id')
                    ->orderByDesc('last_seen_at')
                    ->limit(1),
            ])
            ->when($filters['search'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('employee_id', 'like', "%{$term}%")
                ->orWhere('position', 'like', "%{$term}%")))
            ->when($filters['office'] ?? null, fn ($q, $office) => $q->where('office_id', (int) $office))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Member $member) => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'employee_id' => $member->employee_id,
                'position' => $member->position,
                'office_id' => $member->office_id,
                'office_name' => $member->office?->name,
                'is_active' => $member->is_active,
                'devices_count' => (int) $member->devices_count,
                'last_seen_at' => $member->last_seen_at ? Carbon::parse($member->last_seen_at)->toIso8601String() : null,
            ]);

        return Inertia::render('members/index', [
            'members' => $members,
            'offices' => Office::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'office' => (string) ($filters['office'] ?? ''),
                'status' => $filters['status'] ?? '',
            ],
            'can' => ['manage' => $request->user()->can('assignments.manage')],
        ]);
    }

    public function show(Request $request, Member $officeMember): Response
    {
        $officeMember->load(['office' => fn ($q) => $q->withTrashed()->select('id', 'name')]);

        $devices = MemberDeviceAssignment::query()
            ->where('member_id', $officeMember->id)
            ->with(['device' => fn ($q) => $q->withTrashed()->select('id', 'name', 'device_type', 'mac_address')])
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn (MemberDeviceAssignment $assignment) => [
                'id' => $assignment->id,
                'device_name' => $assignment->device?->name,
                'device_type' => $assignment->device?->device_type?->label(),
                'mac_address' => $assignment->device?->mac_address,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
                'unassigned_at' => $assignment->unassigned_at?->toIso8601String(),
                'current' => $assignment->unassigned_at === null,
            ])
            ->values();

        $connections = DeviceConnectionSession::query()
            ->where('member_id', $officeMember->id)
            ->with($this->connected->relations())
            ->orderByDesc('started_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($session) => $this->connected->present($session));

        $lastSeen = DeviceConnectionSession::query()->where('member_id', $officeMember->id)->max('last_seen_at');

        if ($connections->currentPage() === 1) {
            $this->audit->record(
                action: 'office_network.member_viewed',
                subject: $officeMember,
                description: "Viewed the connection history of {$officeMember->full_name}.",
                actor: $request->user(),
            );
        }

        return Inertia::render('members/show', [
            'member' => [
                'id' => $officeMember->id,
                'full_name' => $officeMember->full_name,
                'employee_id' => $officeMember->employee_id,
                'position' => $officeMember->position,
                'office_name' => $officeMember->office?->name,
                'is_active' => $officeMember->is_active,
            ],
            'devices' => $devices,
            'connections' => $connections,
            'last_seen_at' => $lastSeen ? Carbon::parse($lastSeen)->toIso8601String() : null,
        ]);
    }

    public function store(MemberRequest $request): RedirectResponse
    {
        $member = Member::create([...$request->validated(), 'is_active' => true]);

        $this->audit->record(
            action: 'member.created',
            subject: $member,
            description: "Member {$member->full_name} added.",
            metadata: ['office_id' => $member->office_id],
            actor: $request->user(),
        );

        return back()->with('success', "{$member->full_name} added.");
    }

    public function update(MemberRequest $request, Member $officeMember): RedirectResponse
    {
        $officeMember->fill($request->validated());
        $changes = $officeMember->getDirty();

        if ($changes === []) {
            return back()->with('success', 'Nothing changed.');
        }

        if (array_key_exists('is_active', $changes) && ! $officeMember->is_active) {
            $assigned = MemberDeviceAssignment::query()
                ->where('member_id', $officeMember->id)
                ->whereNull('unassigned_at')
                ->count();

            if ($assigned > 0) {
                return back()->with('error', "{$officeMember->full_name} still has {$assigned} assigned device(s). Unassign them first.");
            }
        }

        $officeMember->save();

        if (array_key_exists('office_id', $changes)) {
            $this->assignments->syncMemberOffice($officeMember);
        }

        $this->audit->record(
            action: 'member.updated',
            subject: $officeMember,
            description: "Member {$officeMember->full_name} updated.",
            metadata: ['changed' => array_keys($changes)],
            actor: $request->user(),
        );

        return back()->with('success', "{$officeMember->full_name} updated.");
    }
}
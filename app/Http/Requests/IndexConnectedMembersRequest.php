<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EndpointType;
use App\Services\ConnectedMemberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexConnectedMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The routes also carry a can: middleware; this is a second, independent check.
        return $this->user()?->can('members.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'office' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'status' => ['nullable', Rule::in(ConnectedMemberService::STATUSES)],
            'type' => ['nullable', Rule::in(EndpointType::values())],
        ];
    }

    /** @return array{search: ?string, office: ?int, status: ?string, type: ?string} */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => $data['search'] ?? null,
            'office' => isset($data['office']) ? (int) $data['office'] : null,
            'status' => $data['status'] ?? null,
            'type' => $data['type'] ?? null,
        ];
    }
}
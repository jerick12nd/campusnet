<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is already behind can:users.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array{search: string, role: string, status: string}
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => trim((string) ($data['search'] ?? '')),
            'role' => (string) ($data['role'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
        ];
    }
}
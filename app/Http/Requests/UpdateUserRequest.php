<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required_without:is_active', Rule::enum(UserRole::class)],
            'is_active' => ['required_without:role', 'boolean'],
        ];
    }

    /**
     * @return array{role?: string, is_active?: bool}
     */
    public function changes(): array
    {
        $data = $this->validated();
        $changes = [];

        if (isset($data['role'])) {
            $changes['role'] = (string) $data['role'];
        }

        if (array_key_exists('is_active', $data)) {
            $changes['is_active'] = (bool) $data['is_active'];
        }

        return $changes;
    }
}
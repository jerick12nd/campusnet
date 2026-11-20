<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignments.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('officeMember')?->id;

        return [
            'office_id' => ['required', 'integer', Rule::exists('offices', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'full_name' => ['required', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:50', Rule::unique('members', 'employee_id')->ignore($id)],
            'position' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
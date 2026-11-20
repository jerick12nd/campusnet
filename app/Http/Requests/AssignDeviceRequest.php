<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignments.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
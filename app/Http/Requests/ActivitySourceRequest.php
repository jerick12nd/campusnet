<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivitySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activity-sources.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'monitored_device_id' => ['nullable', 'integer', Rule::exists('monitored_devices', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_enabled') && ! $this->filled('monitored_device_id')) {
                $validator->errors()->add('monitored_device_id', 'Choose the router this source reads from before enabling it.');
            }
        });
    }
}
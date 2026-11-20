<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EndpointType;
use App\Support\MacAddress;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisteredDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignments.manage') ?? false;
    }

    /** Accept aa-bb-cc..., aabb.ccdd... and so on; the stored form is always AA:BB:CC:DD:EE:FF. */
    protected function prepareForValidation(): void
    {
        $mac = MacAddress::normalize($this->input('mac_address'));

        if ($mac !== null) {
            $this->merge(['mac_address' => $mac]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'device_type' => ['required', Rule::enum(EndpointType::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Only for shared equipment. A personal device takes the office of its member.
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
        ];

        // The MAC address is fixed once registered: it is what identifies the device.
        if ($this->route('registeredDevice') === null) {
            $rules['mac_address'] = [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (MacAddress::normalize((string) $value) === null) {
                        $fail('Use a valid MAC address, for example AA:BB:CC:DD:EE:FF.');
                    }
                },
                Rule::unique('registered_devices', 'mac_address'),
            ];
        }

        return $rules;
    }
}
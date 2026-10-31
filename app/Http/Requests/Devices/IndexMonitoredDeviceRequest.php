<?php

declare(strict_types=1);

namespace App\Http\Requests\Devices;

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMonitoredDeviceRequest extends FormRequest
{
    /** Columns the list may be sorted by. Anything else is rejected, never passed to SQL. */
    public const SORTABLE = [
        'name',
        'management_ip',
        'device_type',
        'location',
        'last_status',
        'last_successful_poll_at',
        'created_at',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('devices.view') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DeviceStatus::class)],
            'type' => ['nullable', Rule::enum(DeviceType::class)],
            'location' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', Rule::in(['yes', 'no'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 15, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
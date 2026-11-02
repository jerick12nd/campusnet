<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'campus_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'bandwidth_capacity_mbps' => ['required', 'integer', 'min:1', 'max:1000000'],
            'warning_threshold_percent' => ['required', 'integer', 'min:1', 'max:99'],
            'critical_threshold_percent' => ['required', 'integer', 'min:2', 'max:100', 'gt:warning_threshold_percent'],
            'dashboard_refresh_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            'stale_data_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'monitoring_interval_seconds' => ['required', 'integer', 'min:15', 'max:3600'],
            'polling_timeout_seconds' => ['required', 'integer', 'min:1', 'max:60', 'lt:monitoring_interval_seconds'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'critical_threshold_percent.gt' => 'The critical threshold must be higher than the warning threshold.',
            'polling_timeout_seconds.lt' => 'The timeout must be shorter than the polling interval.',
        ];
    }
}
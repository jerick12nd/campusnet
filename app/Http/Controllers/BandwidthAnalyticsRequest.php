<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\BandwidthAnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BandwidthAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is already behind can:traffic.view
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(array_keys(BandwidthAnalyticsService::RANGES))],
        ];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? BandwidthAnalyticsService::DEFAULT_RANGE);
    }
}
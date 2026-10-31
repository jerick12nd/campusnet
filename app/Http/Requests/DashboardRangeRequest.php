<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\DashboardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('dashboard.view') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(array_keys(DashboardService::RANGES))],
        ];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? DashboardService::DEFAULT_RANGE);
    }
}
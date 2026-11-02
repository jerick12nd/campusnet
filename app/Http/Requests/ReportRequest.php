<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the routes are already behind can:reports.view / can:reports.export
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(array_keys(ReportService::TYPES))],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('from') || ! $this->filled('to')) {
                    return;
                }

                $days = CarbonImmutable::parse((string) $this->input('from'))
                    ->diffInDays(CarbonImmutable::parse((string) $this->input('to')));

                if ($days > ReportService::MAX_DAYS) {
                    $validator->errors()->add('to', 'Choose a period of at most '.ReportService::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function reportType(): string
    {
        return (string) ($this->validated('type') ?? array_key_first(ReportService::TYPES));
    }

    public function fromDate(): ?string
    {
        $value = $this->validated('from');

        return $value === null ? null : (string) $value;
    }

    public function toDate(): ?string
    {
        $value = $this->validated('to');

        return $value === null ? null : (string) $value;
    }
}
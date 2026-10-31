<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\DashboardService;
use App\Services\TrafficHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TrafficHistoryRequest extends FormRequest
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
            'range' => ['nullable', Rule::in([...array_keys(DashboardService::RANGES), 'custom'])],
            'from' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'interface' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('range') !== 'custom') {
                    return;
                }

                $days = CarbonImmutable::parse((string) $this->input('from'))
                    ->diffInDays(CarbonImmutable::parse((string) $this->input('to')));

                if ($days > TrafficHistoryService::MAX_DAYS) {
                    $validator->errors()->add('to', 'Choose a period of at most '.TrafficHistoryService::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? DashboardService::DEFAULT_RANGE);
    }

    public function fromDate(): ?string
    {
        return $this->validated('from');
    }

    public function toDate(): ?string
    {
        return $this->validated('to');
    }

    public function interfaceId(): ?int
    {
        $id = $this->validated('interface');

        return $id === null ? null : (int) $id;
    }
}
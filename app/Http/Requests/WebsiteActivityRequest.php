<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ObservationType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebsiteActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('website-activity.view')) {
            return false;
        }

        // Filtering by member reveals who visited what, so it is Super Administrator only.
        return ! ($this->filled('member') && ! $user->can('website-activity.member-detail'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'office' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'type' => ['nullable', Rule::enum(ObservationType::class)],
            'member' => ['nullable', 'integer', Rule::exists('members', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : []),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to']) || ! $this->filled('from') || ! $this->filled('to')) {
                return;
            }

            $max = (int) config('office_network.max_report_days');
            $days = CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to')));

            if ($days > $max) {
                $validator->errors()->add('to', "The date range can be at most {$max} days.");
            }
        });
    }

    /** @return array{search: ?string, office: ?int, type: ?string, member: ?int, from: ?string, to: ?string} */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => $data['search'] ?? null,
            'office' => isset($data['office']) ? (int) $data['office'] : null,
            'type' => $data['type'] ?? null,
            'member' => isset($data['member']) ? (int) $data['member'] : null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ];
    }
}
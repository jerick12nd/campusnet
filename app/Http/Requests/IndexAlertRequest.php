<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is already behind can:alerts.view
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['all', 'open', 'active', 'acknowledged', 'resolved'])],
            'severity' => ['nullable', Rule::enum(AlertSeverity::class)],
            'type' => ['nullable', Rule::enum(AlertType::class)],
            'device' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Without a status filter the list shows what needs attention: the open alerts.
     *
     * @return array{status: string, severity: string, type: string, device: string, search: string}
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'status' => (string) ($data['status'] ?? 'open'),
            'severity' => (string) ($data['severity'] ?? ''),
            'type' => (string) ($data['type'] ?? ''),
            'device' => (string) ($data['device'] ?? ''),
            'search' => trim((string) ($data['search'] ?? '')),
        ];
    }
}
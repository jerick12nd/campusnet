<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is already behind can:audit.view
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array{action: string, search: string, from: string, to: string}
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'action' => (string) ($data['action'] ?? ''),
            'search' => trim((string) ($data['search'] ?? '')),
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
        ];
    }
}
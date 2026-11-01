<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\InterfaceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInterfaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is already behind can:interfaces.view
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'device' => ['nullable', 'integer', 'min:1'],
            'role' => ['nullable', Rule::enum(InterfaceRole::class)],
            'monitored' => ['nullable', Rule::in(['yes', 'no'])],
            'status' => ['nullable', Rule::in(['up', 'down', 'unknown'])],
        ];
    }

    /**
     * @return array{search: string, device: string, role: string, monitored: string, status: string}
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => trim((string) ($data['search'] ?? '')),
            'device' => (string) ($data['device'] ?? ''),
            'role' => (string) ($data['role'] ?? ''),
            'monitored' => (string) ($data['monitored'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
        ];
    }
}
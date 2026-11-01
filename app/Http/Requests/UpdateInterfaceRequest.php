<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\InterfaceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterfaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('interfaces.manage') ?? false;
    }

    /**
     * Only these two fields can be changed. Name, index and speed always come from the device itself.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'required', Rule::enum(InterfaceRole::class)],
            'is_monitored' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
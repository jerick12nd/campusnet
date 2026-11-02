<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('alerts.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null ? null : (string) $note;
    }
}
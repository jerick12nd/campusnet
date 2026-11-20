<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('offices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('office')?->id;

        return [
            // Unique across archived offices too, because the database index is.
            'name' => ['required', 'string', 'max:255', Rule::unique('offices', 'name')->ignore($id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('offices', 'code')->ignore($id)],
            'department' => ['nullable', 'string', 'max:255'],
            'subnet_cidr' => ['nullable', 'string', 'max:43', function (string $attribute, mixed $value, Closure $fail): void {
                [$address, $bits] = array_pad(explode('/', (string) $value, 2), 2, '');

                $max = match (true) {
                    filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
                    filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
                    default => null,
                };

                if ($max === null || ! ctype_digit($bits) || (int) $bits > $max) {
                    $fail('Use CIDR notation, for example 192.168.10.0/24.');
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
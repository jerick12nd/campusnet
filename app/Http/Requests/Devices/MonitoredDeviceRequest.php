<?php

declare(strict_types=1);

namespace App\Http\Requests\Devices;

use App\Enums\DeviceType;
use App\Enums\SnmpVersion;
use App\Rules\ManagementIpAddress;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Shared rules for creating and updating a monitored device.
 *
 * SNMP credentials arrive as a nested `credentials` object and are validated per
 * SNMP version. They are only returned by deviceData() when they are actually
 * needed, so editing a device without touching them keeps the stored secrets.
 */
abstract class MonitoredDeviceRequest extends FormRequest
{
    private const SECRET_FIELDS = ['community', 'username', 'auth_password', 'priv_password'];

    public function authorize(): bool
    {
        return $this->user()?->can('devices.manage') ?? false;
    }

    /** Whether new credentials must be supplied with this request. */
    abstract protected function credentialsNeeded(): bool;

    /** Id of the device being edited (ignored by the unique rules), or null when creating. */
    abstract protected function ignoreId(): ?int;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unique = fn (string $column) => Rule::unique('monitored_devices', $column)
            ->whereNull('deleted_at')
            ->ignore($this->ignoreId());

        return [
            'name' => ['required', 'string', 'max:120', $unique('name')],
            'management_ip' => ['required', 'string', 'max:45', new ManagementIpAddress, $unique('management_ip')],
            'vendor' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'device_type' => ['required', Rule::enum(DeviceType::class)],
            'location' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_enabled' => ['required', 'boolean'],
            'snmp_version' => [
                'required',
                Rule::enum(SnmpVersion::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === SnmpVersion::V2c->value && ! config('campusnet.allow_snmp_v2c', false)) {
                        $fail('SNMPv2c is disabled on this server. Use SNMPv3, or set CAMPUSNET_ALLOW_SNMP_V2C=true only for an approved, trusted management network.');
                    }
                },
            ],
            'snmp_port' => ['required', 'integer', 'between:1,65535'],
            'polling_interval_seconds' => ['required', 'integer', 'between:30,3600'],
            ...$this->credentialRules(),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function credentialRules(): array
    {
        $needed = $this->credentialsNeeded();
        $version = $this->input('snmp_version');
        $v2c = $version === SnmpVersion::V2c->value;
        $v3 = $version === SnmpVersion::V3->value;
        $usesPriv = $v3 && $this->input('credentials.security_level') === 'authPriv';

        $required = static fn (bool $applies): string => $needed && $applies ? 'required' : 'nullable';

        return [
            'credentials' => [$needed ? 'required' : 'nullable', 'array'],
            'credentials.community' => [
                $required($v2c), 'string', 'max:64',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && in_array(strtolower(trim($value)), ['public', 'private'], true)) {
                        $fail('The SNMP community must not be a default value such as "public" or "private".');
                    }
                },
            ],
            'credentials.username' => [$required($v3), 'string', 'max:64'],
            // noAuthNoPriv is deliberately not offered: SNMPv3 without authentication is not acceptable here.
            'credentials.security_level' => [$required($v3), Rule::in(['authNoPriv', 'authPriv'])],
            'credentials.auth_protocol' => [$required($v3), Rule::in(['SHA', 'MD5'])],
            'credentials.auth_password' => [$required($v3), 'string', 'min:8', 'max:128'],
            'credentials.priv_protocol' => [$required($usesPriv), Rule::in(['AES', 'DES'])],
            'credentials.priv_password' => [$required($usesPriv), 'string', 'min:8', 'max:128'],
        ];
    }

    /**
     * True when the person typed any secret value. Select boxes always carry a value,
     * so only the secret-bearing fields count.
     */
    protected function hasSecretInput(): bool
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (filled($this->input("credentials.{$field}"))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validated device attributes, with a normalised `credentials` array only when
     * new credentials were supplied.
     *
     * @return array<string, mixed>
     */
    public function deviceData(): array
    {
        $data = Arr::except($this->validated(), ['credentials']);
        $credentials = $this->credentialsPayload();

        if ($credentials !== null) {
            $data['credentials'] = $credentials;
        }

        return $data;
    }

    /**
     * @return array<string, string>|null
     */
    private function credentialsPayload(): ?array
    {
        if (! $this->credentialsNeeded()) {
            return null;
        }

        /** @var array<string, mixed> $input */
        $input = (array) $this->validated('credentials');

        if ($this->input('snmp_version') === SnmpVersion::V2c->value) {
            return ['community' => trim((string) $input['community'])];
        }

        $payload = [
            'username' => trim((string) $input['username']),
            'security_level' => (string) $input['security_level'],
            'auth_protocol' => (string) $input['auth_protocol'],
            'auth_password' => (string) $input['auth_password'],
        ];

        if ($payload['security_level'] === 'authPriv') {
            $payload['priv_protocol'] = (string) $input['priv_protocol'];
            $payload['priv_password'] = (string) $input['priv_password'];
        }

        return $payload;
    }
}
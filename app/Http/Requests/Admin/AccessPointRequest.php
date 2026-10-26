<?php

namespace App\Http\Requests\Admin;

use App\Enums\NetworkStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Wi-Fi access point.
 *
 * `status` here is the ADMINISTRATIVE state and may be `maintenance`, which an
 * engineer sets to suppress alarms during planned work. It is a wider set than
 * telemetry_readings.status, which records what was measured.
 */
class AccessPointRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('network_devices', 'code')->ignore($this->route('access_point')),
            ],
            'location_id' => ['required', 'exists:locations,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'mac_address' => [
                'required', 'string', 'max:17',
                // A MAC identifies the hardware. Two records claiming one
                // means somebody entered the same device twice.
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',
                Rule::unique('network_devices', 'mac_address')->ignore($this->route('access_point')),
            ],
            'ip_address' => ['nullable', 'ip'],
            'client_capacity' => ['required', 'integer', 'min:1', 'max:2000'],
            'installed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(NetworkStatus::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another device already uses that code.',
            'mac_address.unique' => 'That MAC address is already registered to another device.',
            'mac_address.regex' => 'Use the form 00:1B:44:11:3A:B7.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => $this->filled('code') ? strtoupper(trim($this->string('code'))) : null,
            // Accept 00-1B-44 and lowercase; store one canonical form so a
            // duplicate cannot slip past the unique index on formatting alone.
            'mac_address' => $this->filled('mac_address')
                ? strtoupper(str_replace('-', ':', trim($this->string('mac_address'))))
                : null,
        ]));
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Models\NetworkDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * A switch, router, server or other device on the network (ADR-0016).
 *
 * Access points are added through AccessPointRequest instead, which asks for
 * the capacity and MAC address only they need, so `access_point` is not a
 * kind this form accepts.
 */
class NetworkDeviceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(DeviceKind::nonAccessPointOptions()))],
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('network_devices', 'code')->ignore($this->device()),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'location_id' => ['required', 'exists:locations,id'],
            'parent_id' => ['nullable', 'integer', self::parentRule()],
            // Which agent may report its readings (ADR-0017).
            'agent_id' => ['nullable', 'integer', Rule::exists('agents', 'id')->whereNull('deleted_at')],
            'model' => ['nullable', 'string', 'max:255'],
            'mac_address' => [
                'nullable', 'string', 'max:17',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',
                Rule::unique('network_devices', 'mac_address')->ignore($this->device()),
            ],
            'ip_address' => ['nullable', 'ip'],
            'installed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(NetworkStatus::class)],
        ];
    }

    /**
     * What a device can hang from: one still in the inventory, and not an
     * access point. Shared with AccessPointRequest.
     */
    public static function parentRule(): Exists
    {
        return Rule::exists('network_devices', 'id')
            ->whereNull('deleted_at')
            ->whereNot('kind', DeviceKind::AccessPoint->value);
    }

    /**
     * No loops. Phase 2 opens one incident for a parent and the devices below
     * it, and a loop has no top to open it on.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $device = $this->device();

            if (! $device || ! $this->filled('parent_id') || $validator->errors()->has('parent_id')) {
                return;
            }

            $parent = NetworkDevice::find($this->integer('parent_id'));

            if ($parent && $device->isSelfOrAncestorOf($parent)) {
                $validator->errors()->add('parent_id', $parent->is($device)
                    ? 'A device can\'t be connected to itself.'
                    : "{$parent->code} is connected below {$device->code}, so it can't be its parent.");
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.in' => 'Choose a switch, router, server or other device. Access points are added under Access points.',
            'code.unique' => 'Another device already uses that code.',
            'parent_id.exists' => 'Choose a switch, router, server or other device that is still in the inventory.',
            'mac_address.unique' => 'That MAC address is already registered to another device.',
            'agent_id.exists' => 'Choose an agent that is still in use.',
            'mac_address.regex' => 'Use the form 00:1B:44:11:3A:B7.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => $this->filled('code') ? strtoupper(trim($this->string('code'))) : null,
            'mac_address' => $this->filled('mac_address')
                ? strtoupper(str_replace('-', ':', trim($this->string('mac_address'))))
                : null,
        ]));
    }

    /** The device being edited, or null when adding one. */
    private function device(): ?NetworkDevice
    {
        $device = $this->route('network_device');

        return $device instanceof NetworkDevice ? $device : null;
    }
}

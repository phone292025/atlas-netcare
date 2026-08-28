<?php

namespace App\Http\Requests\Admin;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A lab workstation.
 *
 * `risk_score`, `risk_band` and `last_inspected_at` are absent on purpose:
 * they are written by M7's scoring run and M3's inspection service, never
 * from a form.
 */
class LabPcRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_tag' => [
                'required', 'string', 'max:40',
                // Deliberately NOT withoutTrashed(). A retired machine keeps
                // its tag, so a new one must not reuse it — the tag is the
                // link between a physical sticker and years of inspection
                // history. The database unique index agrees, so relaxing this
                // would only swap a clear message for a 500.
                Rule::unique('lab_pcs', 'asset_tag')->ignore($this->route('lab_pc')),
            ],
            'location_id' => ['required', 'exists:locations,id'],
            'hostname' => ['nullable', 'string', 'max:60'],
            'cpu' => ['nullable', 'string', 'max:255'],
            'ram_gb' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'storage' => ['nullable', 'string', 'max:60'],
            'os_version' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'warranty_expiry' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            // Retired is excluded because it is not a status you choose: it is
            // what the Retire action writes, alongside the soft-delete stamp.
            // Accepting it here would let a hand-crafted POST mark a machine
            // retired while it stayed in every active list.
            'status' => ['required', Rule::enum(DeviceStatus::class)->except(DeviceStatus::Retired)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'asset_tag.unique' => 'Another machine already carries that asset tag.',
            'purchase_date.before_or_equal' => 'A machine cannot have been bought in the future.',
            'warranty_expiry.after_or_equal' => 'Warranty cannot expire before the machine was bought.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('asset_tag')) {
            $this->merge(['asset_tag' => strtoupper(trim($this->string('asset_tag')))]);
        }
    }
}

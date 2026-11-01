<?php

namespace App\Models;

use App\Enums\NetworkStatus;
use Illuminate\Database\Eloquent\Model;

/** A device's status changing, kept for the flapping rule. */
class DeviceStatusChange extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['network_device_id', 'from_status', 'to_status', 'changed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => NetworkStatus::class,
            'to_status' => NetworkStatus::class,
            'changed_at' => 'datetime',
        ];
    }
}

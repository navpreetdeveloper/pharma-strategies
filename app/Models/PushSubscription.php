<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $hidden = ['endpoint', 'endpoint_hash', 'subscription'];

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'subscription',
        'user_agent',
        'device_name',
        'last_used_at',
    ];

    protected $casts = [
        'endpoint' => 'encrypted',
        'subscription' => 'encrypted:array',
        'last_used_at' => 'datetime',
        'last_delivery_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'last_http_status' => 'integer',
        'last_transport_error' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

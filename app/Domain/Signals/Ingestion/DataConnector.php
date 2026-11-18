<?php

namespace App\Domain\Signals\Ingestion;

use Illuminate\Database\Eloquent\Model;

class DataConnector extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_data_connectors';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'sync_enabled' => 'boolean',
        'schedule_enabled' => 'boolean',
        'region_scope' => 'array',
        'next_sync_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function syncs()
    {
        return $this->hasMany(DataConnectorSync::class, 'connector_id');
    }
}

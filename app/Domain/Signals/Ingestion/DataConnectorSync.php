<?php

namespace App\Domain\Signals\Ingestion;

use Illuminate\Database\Eloquent\Model;

class DataConnectorSync extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_data_connector_syncs';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}

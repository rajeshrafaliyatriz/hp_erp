<?php

namespace App\Domain\Signals\Connectors;

use Illuminate\Database\Eloquent\Model;

/**
 * One organisation's credentials for one provider application. client_id and client_secret are encrypted at rest (Laravel's `encrypted` cast,
 * keyed by APP_KEY) and are hidden from serialisation, so no API response or log line built from this model can carry them.
 */
class ConnectorProviderSetting extends Model
{
    protected $table = 'g2g_connector_provider_settings';

    protected $guarded = [];

    protected $hidden = ['client_id', 'client_secret'];

    protected function casts(): array
    {
        return ['client_id' => 'encrypted', 'client_secret' => 'encrypted'];
    }
}

<?php

namespace App\Domain\Gtm;

use Illuminate\Support\Facades\DB;

/** Tiny key/value store for infrastructure facts that are not tenant data (gtm_system_state): last tick, last daily run. */
final class GtmState
{
    public static function get(string $key): ?string
    {
        $v = DB::table('gtm_system_state')->where('state_key', $key)->value('value');

        return $v === null ? null : (string) $v;
    }

    public static function set(string $key, ?string $value): void
    {
        DB::table('gtm_system_state')->updateOrInsert(['state_key' => $key], ['value' => $value, 'updated_at' => now()]);
    }
}

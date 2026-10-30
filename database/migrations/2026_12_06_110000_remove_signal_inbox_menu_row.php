<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove Signal Inbox from menus everywhere and point Market Signals directly to /signals.
 *
 * 1. Deactivates Signal Inbox (id: 501 or access_link: /module/crm/marketing/signal-inbox) with status = 0.
 * 2. Updates Market Signals (id: 489) to point directly to /signals with level = 2 and no children.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        // Deactivate Signal Inbox everywhere
        DB::table('tblmenumaster_g2g')
            ->where('id', 501)
            ->orWhere('access_link', '/module/crm/marketing/signal-inbox')
            ->orWhere('menu_name', 'Signal Inbox')
            ->update(['status' => 0]);

        // Point Market Signals directly to /signals
        DB::table('tblmenumaster_g2g')
            ->where('id', 489)
            ->orWhere('menu_name', 'Market Signals')
            ->update([
                'access_link' => '/signals',
                'menu_name' => 'Market Signals',
                'status' => 1,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', 501)
            ->update(['status' => 1]);

        DB::table('tblmenumaster_g2g')
            ->where('id', 489)
            ->update(['access_link' => '/module/crm/signals']);
    }
};


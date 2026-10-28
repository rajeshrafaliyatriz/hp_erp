<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a delivery row carry what the provider says happened AFTER it accepted the message.
 *
 * `status` ('sent') only ever meant "the provider accepted it". For WhatsApp, Meta reports what happened next
 * (delivered, read, failed) by calling a webhook; these columns hold that report, keyed by the message id Meta
 * returned at send time. All four are nullable and additive: existing rows and the e-mail and SMS paths are
 * untouched, and a row that never receives a callback simply keeps `delivery_status` NULL - "not reported", which
 * is different from "delivered".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('ai_report_deliveries') || $this->columnExists('ai_report_deliveries', 'provider_message_id')) {
            return;
        }

        Schema::table('ai_report_deliveries', function (Blueprint $table) {
            $table->string('provider_message_id', 190)->nullable()->after('error')->index();
            $table->string('delivery_status', 20)->nullable()->after('provider_message_id'); // delivered | read | failed
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
            $table->text('delivery_error')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        if ($this->columnExists('ai_report_deliveries', 'provider_message_id')) {
            Schema::table('ai_report_deliveries', function (Blueprint $table) {
                $table->dropColumn(['provider_message_id', 'delivery_status', 'delivered_at', 'delivery_error']);
            });
        }
    }

    /** Answered from information_schema: Schema::hasTable/hasColumn throw on this estate's older MariaDB. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])->c > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c > 0;
    }
};

<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GTM customers: renewals and health history.
 *
 *   php artisan migrate --path=database/migrations/2026_10_11_120000_gtm_customers.php
 *
 * gtm_renewals records what people ENTER: the renewal date, value and status of a customer's
 * contract. Nothing here is inferred. If an account has no renewal row, the screens say "no renewal
 * recorded" - they never guess a date.
 *
 * Customer HEALTH is computed on read from activities, contacts and renewals (see CustomerHealth);
 * gtm_health_snapshots keeps one row per account per day (written by `gtm:health-snapshot`) purely so
 * a trend can be shown once there is history. The score is never the only record: `components`
 * stores each input so a score can always be explained.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if (! $this->has('gtm_renewals')) {
            Schema::create('gtm_renewals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('account_id');
                $t->unsignedBigInteger('deal_id')->nullable();           // the won deal this renews, when known
                $t->string('name', 191);
                $t->decimal('amount', 14, 2)->nullable();
                $t->char('currency', 3)->nullable();
                $t->date('renewal_date');
                $t->string('status', 12)->default('upcoming');           // upcoming | in_progress | renewed | churned | cancelled
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->text('outcome_note')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['sub_institute_id', 'status', 'renewal_date'], 'gtm_renewals_tenant_status_date_idx');
                $t->index(['sub_institute_id', 'account_id'], 'gtm_renewals_tenant_account_idx');
            });
        }

        if (! $this->has('gtm_health_snapshots')) {
            Schema::create('gtm_health_snapshots', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('account_id');
                $t->date('snapshot_date');
                $t->unsignedTinyInteger('score')->nullable();            // null = not enough data to score
                $t->string('band', 12);                                  // healthy | watch | at_risk | unknown
                $t->longText('components');                              // JSON: every input behind the score
                $t->timestamp('created_at')->nullable();

                $t->unique(['sub_institute_id', 'account_id', 'snapshot_date'], 'gtm_health_snapshots_unique');
            });
        }

        $this->addMenus([['Customers', '/gtm/customers']]);
    }

    public function down(): void
    {
        $this->removeMenus(['/gtm/customers']);
        Schema::dropIfExists('gtm_health_snapshots');
        Schema::dropIfExists('gtm_renewals');
    }
};

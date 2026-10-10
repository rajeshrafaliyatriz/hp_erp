<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GTM deals: the pipeline records, their stage history, the sidebar page, and activation of the
 * two agents that were waiting for deal data (Deal Coach, RevOps).
 *
 *   php artisan migrate --path=database/migrations/2026_10_10_400000_create_gtm_deals_tables.php
 *
 * Audit result before design (2026-10-10): no deal/pipeline/forecast table exists in hp_erp. The
 * legacy crm_* tables belong to the retired CRM module and are left alone. Nothing existing is
 * altered; gtm_activities.deal_id already exists from the foundation migration.
 *
 * DESIGN NOTES
 *  - amount is nullable and a currency is required whenever an amount is set, so a number can
 *    never be read without its unit and values are never summed across currencies.
 *  - No probability column: a weighted forecast needs probabilities the organisation chooses,
 *    and an invented default would be a fabricated figure.
 *  - gtm_deal_stage_history is what makes "stuck in stage" a measured fact, not a guess.
 * MariaDB 10.1 on live: no JSON type, existence via information_schema, short index prefixes.
 */
return new class extends Migration
{
    private const LINK = '/gtm/deals';

    public function up(): void
    {
        if (! $this->has('gtm_deals')) {
            Schema::create('gtm_deals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('account_id');
                $t->string('name', 191);
                // discovery | qualification | proposal | negotiation | won | lost
                $t->string('stage', 20)->default('discovery');
                $t->decimal('amount', 14, 2)->nullable();
                $t->char('currency', 3)->nullable();
                $t->date('expected_close_date')->nullable();
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->text('next_step')->nullable();
                $t->date('next_step_due')->nullable();
                $t->string('methodology_slug', 100)->nullable();     // gtm_playbooks.slug of a methodology
                $t->timestamp('stage_changed_at')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->text('close_reason')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['sub_institute_id', 'stage'], 'gtm_deals_tenant_stage_idx');
                $t->index(['sub_institute_id', 'account_id'], 'gtm_deals_tenant_account_idx');
                $t->index(['sub_institute_id', 'owner_user_id'], 'gtm_deals_tenant_owner_idx');
            });
        }

        if (! $this->has('gtm_deal_stage_history')) {
            Schema::create('gtm_deal_stage_history', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('deal_id');
                $t->string('from_stage', 20)->nullable();
                $t->string('to_stage', 20);
                $t->text('note')->nullable();
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->timestamp('changed_at')->nullable();

                $t->index(['sub_institute_id', 'deal_id'], 'gtm_deal_stage_history_deal_idx');
            });
        }

        // The two agents that judge deals can now do real work.
        if ($this->has('agentic_agents')) {
            DB::table('agentic_agents')->whereNull('sub_institute_id')->whereIn('slug', ['gtm-deal-coach', 'gtm-revops'])->update(['status' => 'deployed', 'updated_at' => now()]);
        }

        $this->menu();
    }

    public function down(): void
    {
        if ($this->has('agentic_agents')) {
            DB::table('agentic_agents')->whereNull('sub_institute_id')->whereIn('slug', ['gtm-deal-coach', 'gtm-revops'])->update(['status' => 'draft', 'updated_at' => now()]);
        }
        Schema::dropIfExists('gtm_deal_stage_history');
        Schema::dropIfExists('gtm_deals');

        if ($this->has('tblmenumaster_g2g')) {
            $id = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id');
            if ($id) {
                if ($this->has('tblgroupwise_rights_g2g')) {
                    DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->delete();
                }
                DB::table('tblmenumaster_g2g')->where('id', $id)->delete();
            }
        }
    }

    private function menu(): void
    {
        if (! $this->has('tblmenumaster_g2g')) {
            return;
        }
        $root = DB::table('tblmenumaster_g2g')->where('access_link', '/gtm')->value('id');
        if (! $root) {
            return;
        }
        $id = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id')
            ?: DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => 'Deals', 'parent_id' => $root, 'level' => 2, 'access_link' => self::LINK, 'icon' => null, 'page_type' => 'page', 'status' => 1,
                'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $root)->max('sort_order') + 1,
                'sub_institute_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        if (! $this->has('tblgroupwise_rights_g2g') || ! $this->has('tbluserprofilemaster')) {
            return;
        }
        $grants = ['administrator' => [1, 1, 1, 1], 'executive' => [1, 0, 0, 0]];
        foreach (DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']) as $p) {
            $g = $grants[RoleKey::fromProfile($p)] ?? null;
            if ($g === null || DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->where('profile_id', $p->id)->exists()) {
                continue;
            }
            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $p->sub_institute_id, 'menu_id' => $id, 'profile_id' => $p->id, 'can_view' => $g[0], 'can_add' => $g[1],
                'can_edit' => $g[2], 'can_delete' => $g[3], 'dashboard_right' => 0, 'is_mobile' => 0, 'created_at' => now(),
            ]);
        }
    }

    private function has(string $t): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])->c > 0;
    }
};

<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GTM outreach: one row per email a person may approve and send, and the sidebar page.
 *
 *   php artisan migrate --path=database/migrations/2026_10_10_500000_create_gtm_outreach_messages.php
 *
 * LIFECYCLE   draft -> pending_approval -> approved -> sending -> sent | failed
 *             pending_approval -> rejected -> (edit) draft;  any unsent state -> cancelled
 *
 * A message can only be sent from `approved`, the send claims it atomically (approved -> sending),
 * and `approved_hash` pins the exact text that was approved so an edit after approval cannot be
 * sent. A sequence is several rows sharing `sequence_key`, each approved and sent on its own: there
 * is no scheduler that sends by itself. Sent mail is also written to gtm_activities (type email,
 * outbound), which is what makes outreach counts measured rather than estimated.
 * MariaDB 10.1 on live: no JSON type, existence via information_schema.
 */
return new class extends Migration
{
    private const LINK = '/gtm/outreach';

    public function up(): void
    {
        if (! $this->has('gtm_outreach_messages')) {
            Schema::create('gtm_outreach_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('account_id');
                $t->unsignedBigInteger('contact_id');
                $t->unsignedBigInteger('deal_id')->nullable();
                $t->unsignedBigInteger('analysis_id')->nullable();       // the agent result this came from
                $t->string('sequence_key', 40)->nullable();
                $t->unsignedTinyInteger('step')->default(1);
                $t->timestamp('due_at')->nullable();
                $t->string('to_email', 191)->nullable();                 // snapshot taken at send time
                $t->string('subject', 255);
                $t->longText('body');
                // draft | pending_approval | approved | rejected | sending | sent | failed | cancelled
                $t->string('status', 20)->default('draft');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('approved_hash', 64)->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->text('decision_note')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->string('provider_message_id', 255)->nullable();
                $t->text('error')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'status'], 'gtm_outreach_tenant_status_idx');
                $t->index(['sub_institute_id', 'contact_id'], 'gtm_outreach_tenant_contact_idx');
                $t->index(['sub_institute_id', 'sequence_key'], 'gtm_outreach_tenant_sequence_idx');
            });
        }

        if (! $this->has('tblmenumaster_g2g')) {
            return;
        }
        $root = DB::table('tblmenumaster_g2g')->where('access_link', '/gtm')->value('id');
        if (! $root) {
            return;
        }
        $id = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id')
            ?: DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => 'Outreach', 'parent_id' => $root, 'level' => 2, 'access_link' => self::LINK, 'icon' => null, 'page_type' => 'page', 'status' => 1,
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

    public function down(): void
    {
        Schema::dropIfExists('gtm_outreach_messages');
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

    private function has(string $t): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])->c > 0;
    }
};

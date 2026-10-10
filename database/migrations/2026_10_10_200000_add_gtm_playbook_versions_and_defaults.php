<?php

use App\Domain\Gtm\SystemPlaybooks;
use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GTM Playbooks - version history, the platform defaults, and the sidebar page.
 *
 *   php artisan migrate --path=database/migrations/2026_10_10_200000_add_gtm_playbook_versions_and_defaults.php
 *
 * WHY NOT ai_templates: that table holds report/chat templates (HTML layout, data_source,
 * output_format) for the AI & Intelligence console and has no history. A playbook is an
 * instruction plus a scoring rubric a GTM agent follows, so it keeps its own table
 * (gtm_playbooks, created in the foundation migration) and gains gtm_playbook_versions here.
 *
 * Defaults are inserted only when no platform row with that slug exists, so re-running
 * never overwrites an edited default. A tenant customises a default by copying it.
 * Revocation is row absence, so rights rows are only inserted; down() removes what it made.
 * MariaDB 10.1 on live: existence is checked through information_schema.
 */
return new class extends Migration
{
    private const LINK = '/gtm/playbooks';

    public function up(): void
    {
        if (! $this->tableExists('gtm_playbook_versions')) {
            Schema::create('gtm_playbook_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('playbook_id');
                $t->unsignedBigInteger('sub_institute_id')->nullable();
                $t->unsignedInteger('version');
                $t->string('title', 191);
                $t->text('description')->nullable();
                $t->longText('body')->nullable();
                $t->longText('inputs')->nullable();
                $t->longText('output_schema')->nullable();
                $t->longText('definition')->nullable();
                $t->string('change_note', 255)->nullable();
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->unique(['playbook_id', 'version'], 'gtm_playbook_versions_unique');
            });
        }

        foreach (SystemPlaybooks::all() as $p) {
            $exists = DB::table('gtm_playbooks')->whereNull('sub_institute_id')->where('slug', $p['slug'])->exists();
            if ($exists) {
                continue;
            }
            $id = DB::table('gtm_playbooks')->insertGetId([
                'sub_institute_id' => null, 'kind' => $p['kind'], 'role' => $p['role'], 'stage' => $p['stage'] ?? null,
                'slug' => $p['slug'], 'title' => $p['title'], 'description' => $p['description'], 'body' => $p['body'],
                'inputs' => json_encode($p['inputs'] ?? []), 'output_schema' => json_encode($p['output_schema'] ?? []),
                'definition' => isset($p['definition']) ? json_encode($p['definition']) : null,
                'status' => 'active', 'version' => 1, 'is_system' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('gtm_playbook_versions')->insert([
                'playbook_id' => $id, 'sub_institute_id' => null, 'version' => 1, 'title' => $p['title'], 'description' => $p['description'],
                'body' => $p['body'], 'inputs' => json_encode($p['inputs'] ?? []), 'output_schema' => json_encode($p['output_schema'] ?? []),
                'definition' => isset($p['definition']) ? json_encode($p['definition']) : null, 'change_note' => 'Platform default', 'created_at' => now(),
            ]);
        }

        $this->menu();
    }

    public function down(): void
    {
        $ids = DB::table('gtm_playbooks')->whereNull('sub_institute_id')->where('is_system', 1)
            ->whereIn('slug', array_column(SystemPlaybooks::all(), 'slug'))->pluck('id');
        DB::table('gtm_playbook_versions')->whereIn('playbook_id', $ids)->delete();
        DB::table('gtm_playbooks')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('gtm_playbook_versions');

        if ($this->tableExists('tblmenumaster_g2g')) {
            $menu = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id');
            if ($menu) {
                if ($this->tableExists('tblgroupwise_rights_g2g')) {
                    DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menu)->delete();
                }
                DB::table('tblmenumaster_g2g')->where('id', $menu)->delete();
            }
        }
    }

    private function menu(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }
        $root = DB::table('tblmenumaster_g2g')->where('access_link', '/gtm')->value('id');
        if (! $root) {
            return; // the foundation menu migration has not run; nothing to hang this under
        }
        $id = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id')
            ?: DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => 'Playbooks', 'parent_id' => $root, 'level' => 2, 'access_link' => self::LINK, 'icon' => null,
                'page_type' => 'page', 'status' => 1,
                'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $root)->max('sort_order') + 1,
                'sub_institute_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);

        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }
        $grants = ['administrator' => [1, 1, 1, 1], 'executive' => [1, 0, 0, 0]];
        foreach (DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']) as $profile) {
            $grant = $grants[RoleKey::fromProfile($profile)] ?? null;
            if ($grant === null || DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->where('profile_id', $profile->id)->exists()) {
                continue;
            }
            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $profile->sub_institute_id, 'menu_id' => $id, 'profile_id' => $profile->id,
                'can_view' => $grant[0], 'can_add' => $grant[1], 'can_edit' => $grant[2], 'can_delete' => $grant[3],
                'dashboard_right' => 0, 'is_mobile' => 0, 'created_at' => now(),
            ]);
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])->c > 0;
    }
};

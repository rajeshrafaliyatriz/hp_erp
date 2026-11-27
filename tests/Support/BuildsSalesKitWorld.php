<?php

namespace Tests\Support;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The database and the people the Sales Kit tests run on (in-memory sqlite): the REAL migrations of the kit table, the Product Master table, the Document Library links table and the Sales Kit
 * menu row; hand-made copies of the document tables (the real document_library migration carries a FULLTEXT index and foreign keys sqlite cannot build, so only the columns the kit reads and
 * writes are made); the rights tables with the columns the rights check reads. Files go to a faked disk. Call buildSalesKitWorld() in setUp().
 */
trait BuildsSalesKitWorld
{
    protected const T = 6;

    protected const T2 = 7;

    protected const KIT = '/portfolio/sales-kit';

    protected const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    protected function buildSalesKitWorld(bool $withKitTable = true, bool $withMenuRow = true): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamps();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->text('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
            $t->string('right_dashboard')->nullable();
        });

        Schema::create('document_library', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('folder_id')->nullable();
            $t->string('title', 191);
            $t->string('title_source', 20)->nullable();
            $t->string('original_file_name')->nullable();
            $t->string('mime_type', 128)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('checksum_sha256', 64)->nullable();
            $t->string('storage_path', 512)->nullable();
            $t->unsignedInteger('current_version')->default(1);
            $t->string('category', 32)->default('personnel');
            $t->string('document_type', 64)->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('visibility', 20)->default('private');
            $t->text('view_principals')->nullable();
            $t->string('processing_status', 20)->default('pending');
            $t->string('source_system', 40)->nullable();
            $t->string('source_table', 64)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('document_library_history', function ($t) {
            $t->id();
            $t->unsignedBigInteger('document_id');
            $t->string('entry_type', 10);
            $t->unsignedInteger('version_number')->nullable();
            $t->string('storage_path', 512)->nullable();
            $t->string('checksum_sha256', 64)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('mime_type', 191)->nullable();
            $t->string('original_file_name')->nullable();
            $t->string('change_note')->nullable();
            $t->string('action', 40)->nullable();
            $t->text('details')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        // What DocumentAccess::effectiveViewPrincipalsForDocument reads when a document is shared with the organization.
        Schema::create('document_library_shares', function ($t) {
            $t->id();
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('viewer_user_id');
        });
        Schema::create('document_folder_shares', function ($t) {
            $t->id();
            $t->unsignedBigInteger('folder_id');
            $t->unsignedBigInteger('viewer_user_id');
        });
        Schema::create('document_folders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();
        });

        $migrations = ['2026_12_07_090000_create_g2g_product_master_table', '2026_11_24_084100_create_document_library_links_table'];
        if ($withKitTable) {
            $migrations[] = '2026_12_30_090000_create_g2g_product_kit_items_table';
        }
        foreach ($migrations as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        // The Market Signals branch as dev has it, then the REAL menu migration adds the Sales Kit row beside the Product Portfolio row.
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 476, 'menu_name' => 'Market Signals', 'parent_id' => 438, 'level' => 3, 'page_type' => 'page', 'access_link' => '/signals', 'icon' => null, 'status' => 1, 'sort_order' => 1],
            ['id' => 479, 'menu_name' => 'Product Portfolio', 'parent_id' => 476, 'level' => 3, 'page_type' => 'page', 'access_link' => '/portfolio', 'icon' => 'mdi mdi-view-grid-outline', 'status' => 1, 'sort_order' => 3],
        ]);
        if ($withMenuRow) {
            (require base_path('database/migrations/2026_12_30_090100_add_sales_kit_menu_row.php'))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake((string) config('documents.disk'));
    }

    protected function kitMenuId(): int
    {
        return (int) DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->value('id');
    }

    /**
     * A profile of an organisation and one signed-in user on it. `$rights` are the Sales Kit rights its group row gives (view, add, edit, delete); `$individual` sets right_<action> on that row.
     *
     * @param list<string> $rights
     * @param array<string, string> $individual action => allow|deny
     * @return array{id:int, profile:int, h:array<string,string>}
     */
    protected function kitUser(int $tenant = self::T, array $rights = ['view'], string $role = 'employee', string $name = 'Asha Rao', ?int $department = null, array $individual = [], bool $row = true): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => $role, 'sub_institute_id' => $tenant]);
        if ($row) {
            $flags = array_combine(array_map(fn ($a) => "can_{$a}", $rights), array_fill(0, count($rights), 1));
            $own = array_combine(array_map(fn ($a) => "right_{$a}", array_keys($individual)), array_values($individual));
            DB::table('tblgroupwise_rights_g2g')->insert(['menu_id' => $this->kitMenuId(), 'profile_id' => $profile, 'sub_institute_id' => (string) $tenant] + $flags + $own);
        }
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => $first, 'last_name' => $last, 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'department_id' => $department, 'status' => 1]);

        return ['id' => $id, 'profile' => $profile, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** A user who manages the kit: every right on the Sales Kit row. @return array{id:int, profile:int, h:array<string,string>} */
    protected function kitManager(int $tenant = self::T, string $name = 'Meera Shah', ?int $department = null): array
    {
        return $this->kitUser($tenant, ['view', 'add', 'edit', 'delete'], 'employee', $name, $department);
    }

    protected function kitProduct(int $tenant, string $name = 'ScholarClone', string $kind = 'product', ?int $parent = null): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => $tenant, 'product_key' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(), 'kind' => $kind, 'parent_id' => $parent, 'name' => $name,
            'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A library document, as a person of the organisation would have filed it. @param array<string, mixed> $over */
    protected function libraryDocument(int $tenant, int $owner, array $over = []): int
    {
        $name = $over['original_file_name'] ?? 'brochure.pdf';

        return (int) DB::table('document_library')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'owner_id' => $owner, 'title' => pathinfo($name, PATHINFO_FILENAME), 'original_file_name' => $name, 'mime_type' => 'application/pdf', 'size' => 4096,
            'storage_path' => 'private/document_library/' . $owner . '_' . uniqid() . '.pdf', 'visibility' => 'organization', 'category' => 'organization', 'processing_status' => 'done',
            'created_by' => $owner, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function upload(string $name, string $mime, int $kilobytes = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, $mime);
    }

    /** Every table the kit writes to, counted: [kit rows, library documents, history rows, link rows]. @return array{int,int,int,int} */
    protected function kitCounts(): array
    {
        return [
            DB::table('g2g_product_kit_items')->count(), DB::table('document_library')->count(),
            DB::table('document_library_history')->count(), DB::table('document_library_links')->count(),
        ];
    }
}

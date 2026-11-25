<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsContactWorld;
use Tests\TestCase;

/**
 * The three S18 artisan commands, on in-memory sqlite only (none is run against a real database by the track that wrote it):
 *
 *   crm:seed-contact-roles   dry run by default; --apply adds the six Role values once; never edits a value that exists; a second run adds nothing
 *   crm:contacts-audit       read-only: counts, duplicates, pointer rows per registered column, pointers to a missing or deleted contact, the unregistered-column scan; refuses --apply
 *   crm:retire-contacts-menu dry run by default; --apply saves a before-image then deletes the two Contacts menu rows and their rights in one transaction; --rollback puts them back
 */
class CrmContactCommandsTest extends TestCase
{
    use BuildsContactWorld;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contact-cmd-' . bin2hex(random_bytes(4));
        File::makeDirectory($this->storage . '/app', 0775, true);
        $this->app->useStoragePath($this->storage);
        $this->buildContactWorld();
        Schema::create('crm_picklist_values', function ($t) {
            $t->id();
            $t->string('module', 30);
            $t->string('field_key', 50);
            $t->string('value', 100);
            $t->string('label', 100);
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('color', 20)->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('status')->default(true);
            $t->unsignedBigInteger('legacy_id')->nullable();
            $t->timestamps();
            $t->unique(['module', 'field_key', 'value'], 'crm_picklist_unique');
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} */
    private function run_(string $command, array $options = []): array
    {
        $code = Artisan::call($command, $options);

        return [$code, Artisan::output()];
    }

    // ── crm:seed-contact-roles ───────────────────────────────────────────────

    public function test_seed_roles_dry_run_writes_nothing_and_apply_adds_the_six_values_once(): void
    {
        [$code, $out] = $this->run_('crm:seed-contact-roles');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('6 value(s) would be added', $out);
        $this->assertSame(0, DB::table('crm_picklist_values')->count());

        [$code, $out] = $this->run_('crm:seed-contact-roles', ['--apply' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertSame(['Decision maker', 'Influencer', 'Billing', 'Technical', 'User', 'Other'],
            DB::table('crm_picklist_values')->where('module', 'contacts')->where('field_key', 'contact_role')->orderBy('sort_order')->pluck('value')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], DB::table('crm_picklist_values')->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all());

        $after = DB::table('crm_picklist_values')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        [$code, $out] = $this->run_('crm:seed-contact-roles', ['--apply' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($after, DB::table('crm_picklist_values')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'a second run changes nothing');
    }

    public function test_seed_roles_never_edits_a_value_that_exists_even_switched_off_or_relabelled(): void
    {
        DB::table('crm_picklist_values')->insert([
            ['module' => 'contacts', 'field_key' => 'contact_role', 'value' => 'Billing', 'label' => 'Accounts', 'sort_order' => 9, 'is_default' => true, 'status' => false],
            ['module' => 'contacts', 'field_key' => 'contact_role', 'value' => 'decision maker', 'label' => 'DM', 'sort_order' => 3, 'is_default' => false, 'status' => true],
            ['module' => 'contacts', 'field_key' => 'salutation', 'value' => 'Other', 'label' => 'Other', 'sort_order' => 1, 'is_default' => false, 'status' => true],
        ]);
        $mine = DB::table('crm_picklist_values')->whereIn('value', ['Billing', 'decision maker'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        [$code, $out] = $this->run_('crm:seed-contact-roles', ['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame($mine, DB::table('crm_picklist_values')->whereIn('value', ['Billing', 'decision maker'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'the existing rows are untouched');
        $this->assertSame(['Influencer', 'Other', 'Technical', 'User'], DB::table('crm_picklist_values')->where('field_key', 'contact_role')->whereNotIn('value', ['Billing', 'decision maker'])->orderBy('value')->pluck('value')->all(),
            'the four that were missing, and the "Other" of another field did not count');
        $this->assertSame(6, DB::table('crm_picklist_values')->where('field_key', 'contact_role')->count());
    }

    public function test_seed_roles_refuses_an_unknown_connection_and_a_missing_table(): void
    {
        [$code, $out] = $this->run_('crm:seed-contact-roles', ['--connection' => 'nowhere']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString("no database connection named 'nowhere'", $out);

        Schema::drop('crm_picklist_values');
        [$code] = $this->run_('crm:seed-contact-roles', ['--apply' => true]);
        $this->assertSame(2, $code);
    }

    public function test_the_role_picklist_is_what_the_api_accepts_for_a_role(): void
    {
        $u = $this->crmUser();
        $this->postJson('/api/crm/contacts', ['lastName' => 'X', 'assignedTo' => 1, 'contactRole' => 'Gatekeeper'], $u['h'])->assertStatus(422);

        DB::table('crm_picklist_values')->insert(['module' => 'contacts', 'field_key' => 'contact_role', 'value' => 'Gatekeeper', 'label' => 'Gatekeeper', 'status' => true]);
        $this->postJson('/api/crm/contacts', ['lastName' => 'X', 'assignedTo' => 1, 'contactRole' => 'Gatekeeper'], $u['h'])->assertCreated();

        DB::table('crm_picklist_values')->where('value', 'Gatekeeper')->update(['status' => false]);
        $this->postJson('/api/crm/contacts', ['lastName' => 'Y', 'assignedTo' => 1, 'contactRole' => 'Gatekeeper'], $u['h'])->assertStatus(422);
        $this->postJson('/api/crm/contacts', ['lastName' => 'Z', 'assignedTo' => 1, 'contactRole' => 'Billing'], $u['h'])->assertCreated();   // a stock value works before the seed has been run
    }

    // ── crm:contacts-audit ───────────────────────────────────────────────────

    public function test_the_audit_counts_finds_duplicates_and_pointers_to_missing_or_deleted_contacts_and_writes_nothing(): void
    {
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $a = $this->makeContact(self::T, ['organization_id' => $acme, 'email' => 'Same@acme.example', 'is_primary' => 1]);
        $b = $this->makeContact(self::T, ['organization_id' => $acme, 'email' => ' same@acme.example']);
        $this->makeContact(self::T, ['first_name' => 'Lonely']);
        $gone = $this->makeContact(self::T, ['organization_id' => $acme, 'deleted_at' => now()]);
        DB::statement('PRAGMA foreign_keys = OFF');   // a pointer to a contact that is not there is exactly what the audit looks for
        $this->makeQuote(self::T, ['contact_id' => $a]);
        $this->makeQuote(self::T, ['contact_id' => $gone]);          // points at a deleted contact
        $this->makeQuote(self::T, ['contact_id' => 99999]);          // points at nothing
        DB::table('crm_email_links')->insert(['email_id' => DB::table('crm_emails')->insertGetId(['subject' => 's', 'body' => 'b', 'to_address' => 'x@y.example', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]),
            'linked_type' => 'CONTACT', 'linked_id' => $b, 'sub_institute_id' => self::T]);
        DB::table('crm_email_links')->insert(['email_id' => 1, 'linked_type' => 'ORGANIZATION', 'linked_id' => $acme, 'sub_institute_id' => self::T]);   // an organisation link is not a contact pointer
        $before = [DB::table('crm_contacts')->get()->all(), DB::table('crm_quotes')->get()->all()];

        [$code, $out] = $this->run_('crm:contacts-audit');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('READ ONLY', $out);
        $this->assertStringContainsString('organisation 1: 3 contact(s), 2 with a company, 1 with no company', $out);
        $this->assertMatchesRegularExpression('/\s2\s+Acme \(id \d+, organisation 1\)\s*$/m', $out, 'the company with the most people');
        $this->assertStringContainsString('1 contact(s) have no company', $out);
        $this->assertStringContainsString('same@acme.example is on 2 contacts of company ' . $acme, $out);
        $this->assertMatchesRegularExpression('/crm_quotes\.contact_id\s+3 \/ 2/', $out, 'three quotes point at a contact, two of them at a missing or deleted one');
        $this->assertMatchesRegularExpression('/crm_email_links\.linked_id \[CONTACT\]\s+1 \/ 0/', $out, 'only the CONTACT link counts');
        $this->assertStringContainsString('2 pointer(s) to a missing or deleted contact', $out);
        $this->assertStringContainsString('none: every column that looks like a contact reference is registered or allow-listed', $out);
        $this->assertEquals($before, [DB::table('crm_contacts')->get()->all(), DB::table('crm_quotes')->get()->all()], 'nothing was written');
    }

    public function test_the_audit_names_a_column_that_looks_like_a_contact_reference_and_nobody_registered(): void
    {
        Schema::create('crm_widgets', function ($t) {
            $t->id();
            $t->unsignedBigInteger('signer_contact_id')->nullable();
        });

        [$code, $out] = $this->run_('crm:contacts-audit');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('crm_widgets.signer_contact_id', $out);
        $this->assertStringNotContainsString('gtm_activities.contact_id', $out, 'the GTM contact ids are allow-listed with their reason');
    }

    public function test_the_audit_only_reads_it_refuses_apply_and_can_look_at_one_organisation(): void
    {
        [$code, $out] = $this->run_('crm:contacts-audit', ['--apply' => true]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('only reads', $out);

        $this->makeContact(self::T, ['organization_id' => $this->makeOrganization(self::T)]);
        $this->makeContact(self::T2, ['organization_id' => $this->makeOrganization(self::T2)]);
        [$code, $out] = $this->run_('crm:contacts-audit', ['--tenant' => '2']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('organisation 2: 1 contact(s)', $out);
        $this->assertStringNotContainsString('organisation 1:', $out);

        $this->assertSame(2, $this->run_('crm:contacts-audit', ['--tenant' => 'x'])[0]);
        $this->assertSame(2, $this->run_('crm:contacts-audit', ['--connection' => 'nowhere'])[0]);
    }

    // ── crm:retire-contacts-menu ─────────────────────────────────────────────

    private function menuWorld(): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['id' => 9001, 'sub_institute_id' => 6, 'menu_id' => 451, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 9002, 'sub_institute_id' => 6, 'menu_id' => 482, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 9003, 'sub_institute_id' => 6, 'menu_id' => 452, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
        ]);
    }

    /** @return array<string, mixed> */
    private function menuSnapshot(): array
    {
        return [
            'menu' => DB::table('tblmenumaster_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'rights' => DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function imagePath(): string
    {
        $files = glob($this->storage . '/app/crm-contacts-menu/*.json') ?: [];
        $this->assertCount(1, $files, 'one before-image was saved');

        return $files[0];
    }

    public function test_retire_dry_run_lists_both_rows_and_the_warning_and_writes_nothing(): void
    {
        $this->menuWorld();
        $before = $this->menuSnapshot();

        [$code, $out] = $this->run_('crm:retire-contacts-menu');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('/module/crm/marketing/contacts', $out);
        $this->assertStringContainsString('/module/crm/support/contacts', $out);
        $this->assertStringContainsString('2 menu row(s) and 2 right(s) would be deleted', $out);
        $this->assertStringContainsString('WARNING', $out);
        $this->assertSame($before, $this->menuSnapshot());
        $this->assertSame([], glob($this->storage . '/app/crm-contacts-menu/*.json') ?: []);
    }

    public function test_retire_apply_saves_the_before_image_deletes_both_rows_and_their_rights_only_and_rollback_puts_them_back(): void
    {
        $this->menuWorld();
        $before = $this->menuSnapshot();

        [$code, $out] = $this->run_('crm:retire-contacts-menu', ['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $after = $this->menuSnapshot();
        $this->assertNotContains(451, array_column($after['menu'], 'id'));
        $this->assertNotContains(482, array_column($after['menu'], 'id'));
        $this->assertContains(452, array_column($after['menu'], 'id'), 'the Organizations row is not touched');
        $this->assertSame([9003], array_column($after['rights'], 'id'), 'only the rights on the two Contacts rows are gone');
        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame([451, 482], array_column($image['menu'], 'id'));
        $this->assertSame([9001, 9002], array_column($image['rights'], 'id'));

        [$code, $out] = $this->run_('crm:retire-contacts-menu', ['--apply' => true]);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($after, $this->menuSnapshot(), 'a second apply changes nothing');

        [$code, $out] = $this->run_('crm:retire-contacts-menu', ['--rollback' => $this->imagePath()]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('UNDONE', $out);
        $this->assertEquals($before, $this->menuSnapshot(), 'back with their own ids, as they were');
    }

    public function test_retire_rollback_refuses_when_an_id_is_taken_or_the_file_holds_another_address_and_a_row_with_children_is_left_alone(): void
    {
        $this->menuWorld();
        $this->run_('crm:retire-contacts-menu', ['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 451, 'menu_name' => 'Taken', 'access_link' => '/module/crm/other']);
        $held = $this->menuSnapshot();
        [$code, $out] = $this->run_('crm:retire-contacts-menu', ['--rollback' => $path]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->menuSnapshot());

        $forged = $this->storage . '/forged.json';
        file_put_contents($forged, json_encode(['format' => 'crm-contacts-menu-before-image/1', 'menu' => [['id' => 900, 'menu_name' => 'Quotes', 'access_link' => '/module/crm/sales/quotes']], 'rights' => []]));
        [$code, $out] = $this->run_('crm:retire-contacts-menu', ['--rollback' => $forged]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('another address', $out);

        // children: a row that still has a child is not deleted
        DB::table('tblmenumaster_g2g')->where('id', 451)->delete();
        DB::table('tblmenumaster_g2g')->insert(['id' => 451, 'menu_name' => 'Contacts', 'access_link' => '/module/crm/marketing/contacts']);
        DB::table('tblmenumaster_g2g')->insert(['id' => 700, 'menu_name' => 'Child', 'parent_id' => 451, 'access_link' => '/module/crm/marketing/contacts/child']);
        $before = $this->menuSnapshot();
        [$code, $out] = $this->run_('crm:retire-contacts-menu', ['--apply' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('would be orphaned', $out);
        $this->assertSame($before, $this->menuSnapshot());

        $this->assertSame(2, $this->run_('crm:retire-contacts-menu', ['--connection' => 'nowhere'])[0]);
        $this->assertSame(2, $this->run_('crm:retire-contacts-menu', ['--apply' => true, '--rollback' => $path])[0]);
    }
}

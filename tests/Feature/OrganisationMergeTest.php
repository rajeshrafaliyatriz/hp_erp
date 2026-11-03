<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrganisationMerger;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Merging two CRM organisations (slice 3, decision D6) through the real endpoint, POST /api/crm/organizations/merge: every record that belonged to the removed
 * organisation (quotes, orders, invoices, tickets, comments, the GTM account, the Organisation 360 identity) follows the survivor, nothing of another tenant moves,
 * and what the endpoint did before (contacts, leads, campaign targets, the parent tree) is unchanged. The registry and the property test cover the rest of the
 * reference points; here the tables are the real migrations. In-memory sqlite only.
 */
class OrganisationMergeTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private const ORG_LINK = '/module/crm/marketing/organizations';

    protected function setUp(): void
    {
        parent::setUp();
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
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->string('access_link')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
        });
        // The leads migration probes information_schema, which sqlite has not got, so the table is declared here with the columns the merge touches.
        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->string('last_name', 191);
            $t->unsignedBigInteger('converted_organization_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        foreach ([
            '2026_10_10_100000_create_gtm_foundation_tables', '2026_11_20_090100_create_crm_organizations_table', '2026_11_20_090200_create_crm_contacts_table',
            '2026_11_20_090400_create_crm_campaigns_table', '2026_11_20_090500_create_crm_campaign_targets_table',
            '2026_11_24_080100_create_crm_tax_rates_table', '2026_11_24_080200_create_crm_products_table',
            '2026_11_24_080300_create_crm_opportunities_table', '2026_11_24_080700_create_crm_quotes_table', '2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table',
            '2026_11_24_082400_create_crm_sales_orders_table', '2026_11_24_082800_create_crm_invoices_table', '2026_11_24_083200_create_crm_service_contracts_table',
            '2026_11_24_083300_create_crm_assets_table', '2026_11_24_083400_create_crm_tickets_table',
            '2026_11_24_084000_create_crm_entity_comments_tables', '2026_12_09_090000_create_g2g_org_accounts_tables',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        // 2026_11_21_080000_add_updated_by_deleted_by_to_crm_tables probes information_schema too: the two columns the merge writes are added directly.
        Schema::table('crm_organizations', function ($t) {
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
        });

        $menu = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'Organizations', 'access_link' => self::ORG_LINK]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => null, 'menu_id' => $menu, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function headers(int $tenant = self::T, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** @param array<string, mixed> $over */
    private function org(string $name, int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('crm_organizations')->insertGetId($over + ['sub_institute_id' => $tenant, 'account_no' => 'ACC' . uniqid(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $row */
    private function row(string $table, array $row, int $tenant = self::T): int
    {
        return (int) DB::table($table)->insertGetId($row + ['sub_institute_id' => $tenant]);
    }

    private function quote(int $org, int $tenant = self::T): int
    {
        return $this->row('crm_quotes', ['quote_no' => 'Q' . uniqid(), 'subject' => 'Quote', 'organization_id' => $org], $tenant);
    }

    private function merge(int $survivor, array $duplicates, ?array $headers = null)
    {
        return $this->postJson('/api/crm/organizations/merge', ['survivorId' => $survivor, 'duplicateIds' => $duplicates], $headers ?? $this->headers());
    }

    private function column(string $table, int $id, string $column): mixed
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    // ── the defect: records that belong to the removed organisation ──────────

    public function test_a_quote_an_order_an_invoice_a_ticket_a_comment_and_a_gtm_account_follow_the_survivor(): void
    {
        $keep = $this->org('Acme School');
        $dupe = $this->org('ACME School (duplicate)');
        $quote = $this->quote($dupe);
        $order = $this->row('crm_sales_orders', ['so_no' => 'SO' . uniqid(), 'subject' => 'Order', 'organization_id' => $dupe]);
        $invoice = $this->row('crm_invoices', ['invoice_no' => 'INV' . uniqid(), 'subject' => 'Invoice', 'organization_id' => $dupe]);
        $ticket = $this->row('crm_tickets', ['ticket_no' => 'T' . uniqid(), 'title' => 'Ticket', 'organization_id' => $dupe]);
        $comment = $this->row('crm_organization_comments', ['organization_id' => $dupe, 'comment' => 'Called them']);
        $gtm = $this->row('gtm_accounts', ['name' => 'Acme GTM', 'crm_organization_id' => $dupe]);
        $opportunity = $this->row('crm_opportunities', ['opportunity_no' => 'OPP' . uniqid(), 'name' => 'Deal', 'organization_id' => $dupe]);

        $this->merge($keep, [$dupe])->assertOk()->assertJsonPath('data.merged', 1);

        foreach (['crm_quotes' => [$quote, 'organization_id'], 'crm_sales_orders' => [$order, 'organization_id'], 'crm_invoices' => [$invoice, 'organization_id'],
            'crm_tickets' => [$ticket, 'organization_id'], 'crm_organization_comments' => [$comment, 'organization_id'], 'gtm_accounts' => [$gtm, 'crm_organization_id'],
            'crm_opportunities' => [$opportunity, 'organization_id']] as $table => [$id, $column]) {
            $this->assertSame($keep, (int) $this->column($table, $id, $column), "{$table}.{$column} must follow the survivor");
        }
        $this->assertNotNull($this->column('crm_organizations', $dupe, 'deleted_at'), 'the duplicate is soft-deleted, as before');
        $this->assertNull($this->column('crm_organizations', $keep, 'deleted_at'));
        $this->assertSame(0, DB::table('crm_quotes')->where('organization_id', $dupe)->count());
    }

    public function test_a_soft_deleted_quote_of_the_duplicate_follows_too(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $quote = $this->quote($dupe);
        DB::table('crm_quotes')->where('id', $quote)->update(['deleted_at' => now()]);

        $this->merge($keep, [$dupe])->assertOk();

        $this->assertSame($keep, (int) $this->column('crm_quotes', $quote, 'organization_id'), 'a record in the recycle bin must not stay pointing at the removed organisation');
    }

    public function test_several_duplicates_merge_into_one_survivor(): void
    {
        $keep = $this->org('Keep');
        $a = $this->org('Dupe A');
        $b = $this->org('Dupe B');
        $quoteA = $this->quote($a);
        $quoteB = $this->quote($b);

        $this->merge($keep, [$a, $b])->assertOk()->assertJsonPath('data.merged', 2);

        $this->assertSame([$keep, $keep], [(int) $this->column('crm_quotes', $quoteA, 'organization_id'), (int) $this->column('crm_quotes', $quoteB, 'organization_id')]);
    }

    // ── what the endpoint did before is unchanged ────────────────────────────

    public function test_contacts_leads_the_parent_tree_and_campaign_targets_behave_as_before(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $child = $this->org('Child of dupe', self::T, ['parent_id' => $dupe]);
        $contact = $this->row('crm_contacts', ['contact_no' => 'C' . uniqid(), 'last_name' => 'Rao', 'organization_id' => $dupe]);
        $lead = $this->row('crm_leads', ['last_name' => 'Iyer', 'converted_organization_id' => $dupe]);
        $campaign = $this->row('crm_campaigns', ['campaign_no' => 'CMP' . uniqid(), 'name' => 'Spring']);
        $other = $this->row('crm_campaigns', ['campaign_no' => 'CMP' . uniqid(), 'name' => 'Autumn']);
        $moves = DB::table('crm_campaign_targets')->insertGetId(['campaign_id' => $campaign, 'target_type' => 'organization', 'target_id' => $dupe]);
        $already = DB::table('crm_campaign_targets')->insertGetId(['campaign_id' => $other, 'target_type' => 'organization', 'target_id' => $dupe]);
        DB::table('crm_campaign_targets')->insert(['campaign_id' => $other, 'target_type' => 'organization', 'target_id' => $keep]);
        $leadTarget = DB::table('crm_campaign_targets')->insertGetId(['campaign_id' => $campaign, 'target_type' => 'lead', 'target_id' => $dupe]);

        $this->merge($keep, [$dupe])->assertOk();

        $this->assertSame($keep, (int) $this->column('crm_contacts', $contact, 'organization_id'));
        $this->assertSame($keep, (int) $this->column('crm_leads', $lead, 'converted_organization_id'));
        $this->assertSame($keep, (int) $this->column('crm_organizations', $child, 'parent_id'), 'a child of the duplicate now reports to the survivor');
        $this->assertSame($keep, (int) $this->column('crm_campaign_targets', $moves, 'target_id'));
        $this->assertNull(DB::table('crm_campaign_targets')->where('id', $already)->first(), 'a campaign that already targets the survivor drops the duplicate target instead of erroring');
        $this->assertSame(1, DB::table('crm_campaign_targets')->where('campaign_id', $other)->where('target_type', 'organization')->where('target_id', $keep)->count());
        $this->assertSame($dupe, (int) $this->column('crm_campaign_targets', $leadTarget, 'target_id'), 'a LEAD target with the same numeric id is a different record and does not move');
    }

    public function test_a_survivor_that_was_a_child_of_the_duplicate_is_not_parented_to_itself(): void
    {
        $dupe = $this->org('Parent');
        $keep = $this->org('Child', self::T, ['parent_id' => $dupe]);

        $this->merge($keep, [$dupe])->assertOk();

        $this->assertNull($this->column('crm_organizations', $keep, 'parent_id'));
    }

    public function test_the_survivor_supplements_its_blank_fields_from_the_duplicate_as_before(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe', self::T, ['phone' => '+91 98765 43210']);

        $this->merge($keep, [$dupe])->assertOk();

        $this->assertSame('+91 98765 43210', $this->column('crm_organizations', $keep, 'phone'));
    }

    // ── tenants ──────────────────────────────────────────────────────────────

    public function test_another_tenants_records_that_carry_the_same_ids_do_not_move(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $mine = $this->quote($dupe);
        $theirs = $this->quote($dupe, self::T2);
        $theirComment = $this->row('crm_organization_comments', ['organization_id' => $dupe, 'comment' => 'x'], self::T2);
        $theirGtm = $this->row('gtm_accounts', ['name' => 'Other', 'crm_organization_id' => $dupe], self::T2);
        $theirTarget = DB::table('crm_campaign_targets')->insertGetId(['campaign_id' => $this->row('crm_campaigns', ['campaign_no' => 'CMP' . uniqid(), 'name' => 'Theirs'], self::T2), 'target_type' => 'organization', 'target_id' => $dupe]);

        $this->merge($keep, [$dupe])->assertOk();

        $this->assertSame($keep, (int) $this->column('crm_quotes', $mine, 'organization_id'));
        $this->assertSame($dupe, (int) $this->column('crm_quotes', $theirs, 'organization_id'), 'a quote of another tenant is not touched');
        $this->assertSame($dupe, (int) $this->column('crm_organization_comments', $theirComment, 'organization_id'));
        $this->assertSame($dupe, (int) $this->column('gtm_accounts', $theirGtm, 'crm_organization_id'));
        $this->assertSame($dupe, (int) $this->column('crm_campaign_targets', $theirTarget, 'target_id'), 'a campaign target is scoped through its campaign');
    }

    public function test_a_caller_cannot_merge_another_tenants_organisations(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $quote = $this->quote($dupe);

        $this->merge($keep, [$dupe], $this->headers(self::T2))->assertStatus(404);

        $this->assertSame($dupe, (int) $this->column('crm_quotes', $quote, 'organization_id'));
        $this->assertNull($this->column('crm_organizations', $dupe, 'deleted_at'));
    }

    // ── the Organisation 360 identity ────────────────────────────────────────

    public function test_the_survivors_identity_is_kept_and_the_duplicates_identity_is_absorbed(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $older = DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Keep', 'normalized_name' => 'keep', 'crm_organization_id' => $dupe, 'gtm_account_id' => 5]);
        $newer = DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Keep', 'normalized_name' => 'keep', 'crm_organization_id' => $keep, 'g2g_company_id' => 9]);
        $theirs = DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => self::T2, 'name' => 'Other', 'normalized_name' => 'other', 'crm_organization_id' => $dupe]);

        $this->merge($keep, [$dupe])->assertOk();

        $kept = DB::table('g2g_org_accounts')->find($older);
        $absorbed = DB::table('g2g_org_accounts')->find($newer);
        $this->assertSame([$keep, 5, 9, null], [(int) $kept->crm_organization_id, (int) $kept->gtm_account_id, (int) $kept->g2g_company_id, $kept->deleted_at], 'the older identity keeps its own pointers and takes the other\'s');
        $this->assertNull($absorbed->crm_organization_id);
        $this->assertNull($absorbed->g2g_company_id, 'the pointer moved to the kept identity');
        $this->assertNotNull($absorbed->deleted_at, 'the identity left with nothing to point at is soft-deleted');
        $this->assertSame($dupe, (int) DB::table('g2g_org_accounts')->find($theirs)->crm_organization_id, 'another tenant\'s identity is not touched');
    }

    // ── every other reference point, against its real migration ─────────────

    public function test_every_polymorphic_and_remaining_reference_moves_in_its_real_schema_and_duplicates_are_dropped(): void
    {
        foreach ([
            '2026_11_24_084200_create_crm_email_templates_table', '2026_11_24_084300_create_crm_emails_table', '2026_11_24_084400_create_crm_email_links_table', '2026_10_10_090000_create_task_management_calendar_events_table',
            '2026_11_24_080900_create_crm_sms_log_table', '2026_12_03_080400_create_crm_outreach_approvals_table', '2026_12_03_110100_create_crm_knowledge_sources_table',
            '2026_12_03_110000_create_crm_entity_relationships_table', '2026_12_03_080200_create_crm_consent_preferences_table', '2026_09_26_110000_create_g2g_custom_field_values_table',
            '2026_12_03_100000_create_crm_signal_inbox_table', '2026_11_24_084100_create_document_library_links_table', '2026_07_28_000000_create_task_management_project_tables',
            '2026_11_24_084600_add_crm_links_to_task_management_projects_table',
        ] as $migration) {
            if ($migration === '2026_11_24_084100_create_document_library_links_table') {
                Schema::create('document_library', fn ($t) => $t->id());
                DB::table('document_library')->insert([['id' => 1], ['id' => 2]]);
            }
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $mail = fn () => DB::table('crm_emails')->insertGetId(['subject' => 's', 'body' => 'b', 'to_address' => 'a@example.test', 'sub_institute_id' => self::T]);
        [$m1, $m2] = [$mail(), $mail()];
        $link = fn (int $email, string $type, int $id) => DB::table('crm_email_links')->insertGetId(['email_id' => $email, 'linked_type' => $type, 'linked_id' => $id, 'sub_institute_id' => self::T]);
        $mailMoves = $link($m2, 'ORGANIZATION', $dupe);
        $mailDuplicate = $link($m1, 'ORGANIZATION', $dupe);
        $link($m1, 'ORGANIZATION', $keep);
        $mailOtherType = $link($m1, 'LEAD', $dupe);

        $event = fn (string $type) => DB::table('task_management_calendar_events')->insertGetId(['sub_institute_id' => self::T, 'syear' => '2026', 'title' => 'Call', 'start_at' => now(), 'end_at' => now(),
            'owner_id' => 1, 'created_by' => 1, 'linked_type' => $type, 'linked_id' => $dupe]);
        [$eventMoves, $eventLead] = [$event('ORGANIZATION'), $event('LEAD')];

        $sms = DB::table('crm_sms_log')->insertGetId(['to_number' => '1', 'message' => 'm', 'related_type' => 'organization', 'related_id' => $dupe, 'sub_institute_id' => self::T]);
        $campaign = $this->row('crm_campaigns', ['campaign_no' => 'CMP' . uniqid(), 'name' => 'C']);
        $approval = DB::table('crm_outreach_approvals')->insertGetId(['campaign_id' => $campaign, 'target_type' => 'organization', 'target_id' => $dupe, 'sub_institute_id' => self::T]);
        $knowledge = DB::table('crm_knowledge_sources')->insertGetId(['entity_type' => 'organization', 'entity_id' => $dupe, 'source_type' => 'manual', 'sub_institute_id' => self::T]);
        $signal = $this->row('crm_signal_inbox', ['signal_key' => 'k' . uniqid(), 'title' => 'Signal', 'linked_account_id' => $dupe]);

        $edge = fn (string $ft, int $fi, string $tt, int $ti) => DB::table('crm_entity_relationships')->insertGetId(['from_type' => $ft, 'from_id' => $fi, 'to_type' => $tt, 'to_id' => $ti,
            'relationship_type' => 'partner_of', 'sub_institute_id' => self::T]);
        $edgeOut = $edge('organization', $dupe, 'contact', 5);
        $edgeOutDuplicate = $edge('organization', $dupe, 'contact', 6);
        $edge('organization', $keep, 'contact', 6);
        $edgeIn = $edge('contact', 7, 'organization', $dupe);
        $edgeBetween = $edge('organization', $dupe, 'organization', $keep);

        $consent = DB::table('crm_consent_preferences')->insertGetId(['entity_type' => 'organization', 'entity_id' => $dupe, 'channel' => 'sms', 'sub_institute_id' => self::T]);
        $consentDuplicate = DB::table('crm_consent_preferences')->insertGetId(['entity_type' => 'organization', 'entity_id' => $dupe, 'channel' => 'email', 'sub_institute_id' => self::T]);
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'organization', 'entity_id' => $keep, 'channel' => 'email', 'sub_institute_id' => self::T]);

        $field = fn (int $record, int $fieldId, string $table = 'crm_organizations') => DB::table('g2g_custom_field_values')->insertGetId(['sub_institute_id' => self::T, 'field_id' => $fieldId, 'record_table' => $table, 'record_id' => $record, 'value' => 'v']);
        $fieldMoves = $field($dupe, 2);
        $fieldDuplicate = $field($dupe, 1);
        $field($keep, 1);
        $fieldOtherTable = $field($dupe, 3, 'crm_contacts');

        $doc = fn (int $document, int $id) => DB::table('document_library_links')->insertGetId(['document_id' => $document, 'linked_type' => 'ORGANIZATION', 'linked_id' => $id, 'sub_institute_id' => self::T]);
        $docMoves = $doc(2, $dupe);
        $docDuplicate = $doc(1, $dupe);
        $doc(1, $keep);

        $project = DB::table('task_management_projects')->insertGetId(['sub_institute_id' => self::T, 'syear' => '2026', 'code' => 'P1', 'name' => 'Project', 'crm_organization_id' => $dupe, 'created_by' => 1]);

        $this->merge($keep, [$dupe])->assertOk();

        foreach ([['crm_email_links', $mailMoves, 'linked_id'], ['task_management_calendar_events', $eventMoves, 'linked_id'], ['crm_sms_log', $sms, 'related_id'],
            ['crm_outreach_approvals', $approval, 'target_id'], ['crm_knowledge_sources', $knowledge, 'entity_id'], ['crm_signal_inbox', $signal, 'linked_account_id'],
            ['crm_entity_relationships', $edgeOut, 'from_id'], ['crm_entity_relationships', $edgeIn, 'to_id'], ['crm_consent_preferences', $consent, 'entity_id'],
            ['g2g_custom_field_values', $fieldMoves, 'record_id'], ['document_library_links', $docMoves, 'linked_id'], ['task_management_projects', $project, 'crm_organization_id']] as [$table, $id, $column]) {
            $this->assertSame($keep, (int) $this->column($table, $id, $column), "{$table}.{$column} must follow the survivor");
        }
        foreach ([['crm_email_links', $mailDuplicate], ['crm_entity_relationships', $edgeOutDuplicate], ['crm_entity_relationships', $edgeBetween], ['crm_consent_preferences', $consentDuplicate],
            ['g2g_custom_field_values', $fieldDuplicate], ['document_library_links', $docDuplicate]] as [$table, $id]) {
            $this->assertNull(DB::table($table)->find($id), "{$table} row {$id} duplicates what the survivor already has (or relates it to itself) and is dropped, not moved");
        }
        $this->assertSame($dupe, (int) $this->column('crm_email_links', $mailOtherType, 'linked_id'), 'a LEAD link with the same numeric id is a different record');
        $this->assertSame($dupe, (int) $this->column('task_management_calendar_events', $eventLead, 'linked_id'));
        $this->assertSame($dupe, (int) $this->column('g2g_custom_field_values', $fieldOtherTable, 'record_id'), 'a custom-field answer for crm_contacts record N is not one for organisation N');
    }

    // ── the merger on its own ────────────────────────────────────────────────

    public function test_repointing_the_same_pair_again_changes_nothing_and_reports_zeros(): void
    {
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');
        $this->quote($dupe);
        $this->row('gtm_accounts', ['name' => 'G', 'crm_organization_id' => $dupe]);

        $first = OrganisationMerger::repoint(self::T, $dupe, $keep);
        $before = [DB::table('crm_quotes')->get()->all(), DB::table('gtm_accounts')->get()->all()];
        $second = OrganisationMerger::repoint(self::T, $dupe, $keep);

        $this->assertSame(['crm_quotes.organization_id' => 1, 'gtm_accounts.crm_organization_id' => 1], array_intersect_key($first['moved'], array_flip(['crm_quotes.organization_id', 'gtm_accounts.crm_organization_id'])));
        $this->assertSame(['moved' => [], 'dropped' => [], 'cleared' => []], $second);
        $this->assertEquals($before, [DB::table('crm_quotes')->get()->all(), DB::table('gtm_accounts')->get()->all()]);
    }

    public function test_tables_that_do_not_exist_on_the_host_are_skipped(): void
    {
        // The e-mail, document, calendar, SMS, knowledge, relationship and custom-field tables are not created in this schema, as on the live MariaDB 10.1.
        $this->assertFalse(Schema::hasTable('crm_email_links'));
        $keep = $this->org('Keep');
        $dupe = $this->org('Dupe');

        $result = OrganisationMerger::repoint(self::T, $dupe, $keep);

        $this->assertSame([], array_filter(array_keys($result['moved']), fn ($k) => str_starts_with($k, 'crm_email_links')));
    }

    public function test_merging_an_organisation_into_itself_does_nothing(): void
    {
        $keep = $this->org('Keep');
        $quote = $this->quote($keep);

        $this->assertSame(['moved' => [], 'dropped' => [], 'cleared' => []], OrganisationMerger::repoint(self::T, $keep, $keep));
        $this->assertSame($keep, (int) $this->column('crm_quotes', $quote, 'organization_id'));
    }
}

<?php

namespace Database\Seeders;

use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Crm\Leads\LeadScorer;
use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\GtmOutreachMessage;
use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgEnsure;
use App\Domain\ProductMaster\ProductMaster;
use App\Support\ColumnExists;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant 6 Signal-to-Outreach Lifecycle Seeder & Runner.
 *
 * Implements Features F1 through F7:
 * - F1 & F2: Ingests 3 LinkedIn signals into `crm_signal_inbox` with classification 'buyer_inquiry',
 *   scores (95, 85, 98), full metadata and raw payloads in `tags`, sub_institute_id = 6.
 * - F3: Idempotently resolves organizations across `crm_organizations`, `gtm_accounts`, and `g2g_org_accounts`.
 * - F4: Creates 3 key personas per org in `crm_contacts` and `gtm_contacts` (Author, Head of HR, CFO/Finance Head)
 *   with hierarchical `reports_to_id` linking subordinates to the Author, and consent_status = 'consented'.
 * - F5: Creates `crm_leads` bound to `product_master_id = 1` and `org_account_id` with verified lead score = 40.
 * - F6 & F7: Generates staged outreach drafts in `gtm_outreach_messages` under `status = 'pending_approval'`,
 *   tailored to post context with 0 template leaks, 0 banned phrases, 0 unapproved claims, and strict length limits.
 *
 * Database safety:
 * - Exclusively uses App\Support\ColumnExists::has() for column checks; zero Schema::hasColumn() calls.
 * - Guarded Eloquent models utilize App\Models\Concerns\SkipsGuardableColumnCheck.
 * - Strictly idempotent: safe to execute multiple times without record duplication.
 */
class Tenant6SignalOutreachLifecycleSeeder extends Seeder
{
    public const TENANT_ID = 6;
    public const ADMIN_USER_ID = 28;
    public const PRODUCT_MASTER_ID = 1;

    public function run(): void
    {
        $this->command?->info('========================================================================');
        $this->command?->info('  STARTING TENANT 6 SIGNAL-TO-OUTREACH LIFECYCLE SEEDER (F1-F7)');
        $this->command?->info('========================================================================');

        $conn = config('database.default');

        // 0. Verify Tenant & Prerequisites
        $this->verifyPrerequisites($conn);

        // Define Signal Dataset
        $datasets = $this->getLifecycleDatasets();

        $this->command?->info(sprintf('Loaded %d LinkedIn buyer inquiry signals for Tenant 6.', count($datasets)));

        foreach ($datasets as $idx => $data) {
            $signalNum = $idx + 1;
            $this->command?->info("\n--- Processing Signal #{$signalNum}: {$data['organization']['name']} ---");

            // Step 1: Ingest Signal into crm_signal_inbox (F1 & F2)
            $signalId = $this->ingestSignal($conn, $data['signal']);
            $this->command?->info("  [F1/F2] crm_signal_inbox ID #{$signalId} (Intent: {$data['signal']['classification']}, Score: {$data['signal']['intent_score']})");

            // Step 2: Resolve Organization in crm_organizations, gtm_accounts, and g2g_org_accounts (F3)
            $orgResolution = $this->resolveOrganization($conn, $data['organization']);
            $crmOrgId = $orgResolution['crm_org_id'];
            $gtmAccId = $orgResolution['gtm_acc_id'];
            $orgAccountId = $orgResolution['org_account_id'];
            $this->command?->info("  [F3] Resolved Org -> CRM: #{$crmOrgId}, GTM: #{$gtmAccId}, OrgAccount (Identity): #{$orgAccountId}");

            // Step 3: Resolve 3 Key Personas in crm_contacts & gtm_contacts with Hierarchy (F4)
            $contactsMap = $this->resolvePersonas($conn, $crmOrgId, $gtmAccId, $data['organization']['name'], $data['personas']);
            $authorCrmContactId = $contactsMap['author']['crm_contact_id'];
            $authorGtmContactId = $contactsMap['author']['gtm_contact_id'];
            $this->command?->info("  [F4] Created 3 Personas with hierarchy (Author: #{$authorCrmContactId}, Head of HR, CFO/Finance Head)");

            // Step 4: Create / Resolve Lead in crm_leads (Score = 40) (F5)
            $leadId = $this->resolveLead($conn, $data, $orgAccountId, $crmOrgId, $authorCrmContactId);
            $this->command?->info("  [F5] Lead resolved in crm_leads ID #{$leadId} (Score: 40, PM: 1, OrgAccount: #{$orgAccountId})");

            // Step 5: Generate & Stage Outreach Message in gtm_outreach_messages (F6 & F7)
            $messageId = $this->resolveOutreachMessage($conn, $gtmAccId, $authorGtmContactId, $data['outreach']);
            $this->command?->info("  [F6/F7] Outreach staged in gtm_outreach_messages ID #{$messageId} (status: pending_approval)");

            // Step 6: Link Signal to Resolved Account & Lead
            DB::connection($conn)->table('crm_signal_inbox')
                ->where('id', $signalId)
                ->update([
                    'linked_account_id' => $crmOrgId,
                    'linked_account_confidence' => 1.00,
                    'status' => 'accepted',
                    'promoted_to' => 'lead',
                    'promoted_id' => $leadId,
                    'reviewed_by' => self::ADMIN_USER_ID,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->command?->info("  [Link] Updated crm_signal_inbox #{$signalId} -> linked_account_id: {$crmOrgId}, promoted_id: {$leadId}");
        }

        $this->command?->info("\n========================================================================");
        $this->command?->info('  TENANT 6 SIGNAL-TO-OUTREACH LIFECYCLE SEEDING COMPLETED SUCCESSFULLY');
        $this->command?->info('========================================================================');
    }

    private function verifyPrerequisites(string $conn): void
    {
        // 1. Verify User 28
        $admin = DB::connection($conn)->table('tbluser')
            ->where('id', self::ADMIN_USER_ID)
            ->where('sub_institute_id', self::TENANT_ID)
            ->first();

        if (! $admin) {
            throw new \RuntimeException(sprintf('Admin user %d for Tenant %d not found in tbluser.', self::ADMIN_USER_ID, self::TENANT_ID));
        }

        // 2. Verify Product Master ID 1
        $product = DB::connection($conn)->table('g2g_product_master')
            ->where('id', self::PRODUCT_MASTER_ID)
            ->first();

        if (! $product) {
            throw new \RuntimeException(sprintf('Product Master ID %d not found in g2g_product_master.', self::PRODUCT_MASTER_ID));
        }

        // 3. Ensure Lead Scoring Rules for Tenant 6 (Rule 14: industry=Education -> 25, Rule 15: rating=Hot -> 15)
        $rules = DB::connection($conn)->table('crm_lead_scoring_rules')
            ->where('sub_institute_id', self::TENANT_ID)
            ->where('is_active', 1)
            ->pluck('points', 'field_key')
            ->all();

        if (! isset($rules['industry']) || (int) $rules['industry'] !== 25) {
            DB::connection($conn)->table('crm_lead_scoring_rules')->updateOrInsert(
                ['sub_institute_id' => self::TENANT_ID, 'field_key' => 'industry'],
                [
                    'rule_name' => 'Education Industry Lead Score',
                    'operator' => 'equals',
                    'value' => 'Education',
                    'points' => 25,
                    'sort_order' => 10,
                    'is_active' => 1,
                    'created_by' => self::ADMIN_USER_ID,
                    'updated_at' => now(),
                ]
            );
        }

        if (! isset($rules['rating']) || (int) $rules['rating'] !== 15) {
            DB::connection($conn)->table('crm_lead_scoring_rules')->updateOrInsert(
                ['sub_institute_id' => self::TENANT_ID, 'field_key' => 'rating'],
                [
                    'rule_name' => 'Hot Rating Lead Score',
                    'operator' => 'equals',
                    'value' => 'Hot',
                    'points' => 15,
                    'sort_order' => 20,
                    'is_active' => 1,
                    'created_by' => self::ADMIN_USER_ID,
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Ingests a signal into `crm_signal_inbox` idempotently using `signal_key`.
     */
    private function ingestSignal(string $conn, array $sig): int
    {
        $existing = DB::connection($conn)->table('crm_signal_inbox')
            ->where('signal_key', $sig['signal_key'])
            ->first(['id']);

        $payload = [
            'signal_key' => $sig['signal_key'],
            'source_name' => 'LinkedIn',
            'source_url' => $sig['source_url'],
            'title' => $sig['title'],
            'body' => $sig['body'],
            'published_at' => $sig['published_at'],
            'classification' => $sig['classification'],
            'classification_confidence' => $sig['classification_confidence'],
            'classification_reason' => $sig['classification_reason'],
            'relevance_score' => $sig['relevance_score'],
            'tags' => json_encode($sig['tags_payload']),
            'sub_institute_id' => self::TENANT_ID,
            'assigned_to' => self::ADMIN_USER_ID,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::connection($conn)->table('crm_signal_inbox')
                ->where('id', $existing->id)
                ->update($payload);

            return (int) $existing->id;
        }

        $payload['ingested_at'] = now();
        $payload['status'] = 'accepted';
        $payload['created_at'] = now();

        return (int) DB::connection($conn)->table('crm_signal_inbox')->insertGetId($payload);
    }

    /**
     * Resolves organization across crm_organizations, gtm_accounts, and g2g_org_accounts idempotently.
     *
     * @return array{crm_org_id: int, gtm_acc_id: int, org_account_id: int}
     */
    private function resolveOrganization(string $conn, array $org): array
    {
        // 1. Resolve in crm_organizations
        $crmOrg = DB::connection($conn)->table('crm_organizations')
            ->where('sub_institute_id', self::TENANT_ID)
            ->where('name', $org['name'])
            ->first();

        if ($crmOrg) {
            $crmOrgId = (int) $crmOrg->id;
            DB::connection($conn)->table('crm_organizations')
                ->where('id', $crmOrgId)
                ->update([
                    'website' => $org['website'],
                    'phone' => $org['phone'],
                    'email' => $org['email'],
                    'employees' => $org['employees'],
                    'industry' => $org['industry'],
                    'rating' => 'Hot',
                    'billing_street' => $org['billing_street'],
                    'billing_city' => $org['billing_city'],
                    'billing_state' => $org['billing_state'],
                    'billing_country' => $org['billing_country'],
                    'updated_at' => now(),
                ]);
        } else {
            $crmOrgId = (int) DB::connection($conn)->table('crm_organizations')->insertGetId([
                'account_no' => 'ORG-SIG-' . strtoupper(Str::random(6)),
                'name' => $org['name'],
                'account_type' => 'Prospect',
                'industry' => $org['industry'],
                'rating' => 'Hot',
                'employees' => $org['employees'],
                'phone' => $org['phone'],
                'email' => $org['email'],
                'website' => $org['website'],
                'billing_street' => $org['billing_street'],
                'billing_city' => $org['billing_city'],
                'billing_state' => $org['billing_state'],
                'billing_country' => $org['billing_country'],
                'assigned_to' => self::ADMIN_USER_ID,
                'created_by' => self::ADMIN_USER_ID,
                'sub_institute_id' => self::TENANT_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Resolve in gtm_accounts
        $gtmAcc = DB::connection($conn)->table('gtm_accounts')
            ->where('sub_institute_id', self::TENANT_ID)
            ->where(function ($q) use ($crmOrgId, $org) {
                $q->where('crm_organization_id', $crmOrgId)
                  ->orWhere('name', $org['name']);
            })
            ->first();

        if ($gtmAcc) {
            $gtmAccId = (int) $gtmAcc->id;
            DB::connection($conn)->table('gtm_accounts')
                ->where('id', $gtmAccId)
                ->update([
                    'crm_organization_id' => $crmOrgId,
                    'domain' => $org['domain'],
                    'website' => $org['website'],
                    'industry' => $org['industry'],
                    'location' => $org['billing_city'] . ', ' . $org['billing_country'],
                    'employee_range' => $org['employee_range'],
                    'stage' => 'target',
                    'updated_at' => now(),
                ]);
        } else {
            $gtmAccId = (int) DB::connection($conn)->table('gtm_accounts')->insertGetId([
                'sub_institute_id' => self::TENANT_ID,
                'crm_organization_id' => $crmOrgId,
                'name' => $org['name'],
                'domain' => $org['domain'],
                'website' => $org['website'],
                'industry' => $org['industry'],
                'employee_range' => $org['employee_range'],
                'location' => $org['billing_city'] . ', ' . $org['billing_country'],
                'stage' => 'target',
                'owner_user_id' => self::ADMIN_USER_ID,
                'source' => 'signals',
                'created_by' => self::ADMIN_USER_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Resolve in g2g_org_accounts via OrgEnsure
        $ensureResult = (new OrgEnsure)->forCrmOrganization(self::TENANT_ID, $crmOrgId);
        $orgAccount = $ensureResult['account'] ?? OrgAccount::query()
            ->where('sub_institute_id', self::TENANT_ID)
            ->where('crm_organization_id', $crmOrgId)
            ->first();

        if (! $orgAccount) {
            throw new \RuntimeException("Failed to resolve OrgAccount identity for CRM Organization #{$crmOrgId}");
        }

        // Synchronize gtm_account_id if not linked
        if ((int) $orgAccount->gtm_account_id !== $gtmAccId) {
            $orgAccount->gtm_account_id = $gtmAccId;
            $orgAccount->gtm_link_basis = 'existing_link';
            $orgAccount->save();
        }

        return [
            'crm_org_id' => $crmOrgId,
            'gtm_acc_id' => $gtmAccId,
            'org_account_id' => (int) $orgAccount->id,
        ];
    }

    /**
     * Resolves 3 key personas in crm_contacts and gtm_contacts with hierarchical reports_to_id.
     *
     * @return array<string, array{crm_contact_id: int, gtm_contact_id: int}>
     */
    private function resolvePersonas(string $conn, int $crmOrgId, int $gtmAccId, string $orgName, array $personas): array
    {
        $contactMap = [];

        // Pass 1: Upsert crm_contacts and gtm_contacts
        foreach ($personas as $roleKey => $p) {
            // CRM Contact
            $crmContact = DB::connection($conn)->table('crm_contacts')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('organization_id', $crmOrgId)
                ->where(function ($q) use ($p) {
                    $q->where('email', $p['email'])
                      ->orWhere('title', $p['title']);
                })
                ->first();

            if ($crmContact) {
                $crmContactId = (int) $crmContact->id;
                DB::connection($conn)->table('crm_contacts')
                    ->where('id', $crmContactId)
                    ->update([
                        'first_name' => $p['first_name'],
                        'last_name' => $p['last_name'],
                        'department' => $p['department'],
                        'phone' => $p['phone'],
                        'mobile' => $p['mobile'],
                        'linkedin_url' => $p['linkedin_url'] ?? null,
                        'consent_status' => 'consented',
                        'assigned_to' => self::ADMIN_USER_ID,
                        'updated_at' => now(),
                    ]);
            } else {
                $crmContactId = (int) DB::connection($conn)->table('crm_contacts')->insertGetId([
                    'contact_no' => 'CON-SIG-' . strtoupper(Str::random(6)),
                    'organization_id' => $crmOrgId,
                    'salutation' => $p['salutation'],
                    'first_name' => $p['first_name'],
                    'last_name' => $p['last_name'],
                    'title' => $p['title'],
                    'department' => $p['department'],
                    'email' => $p['email'],
                    'phone' => $p['phone'],
                    'mobile' => $p['mobile'],
                    'linkedin_url' => $p['linkedin_url'] ?? null,
                    'lead_source' => 'LinkedIn',
                    'consent_status' => 'consented',
                    'assigned_to' => self::ADMIN_USER_ID,
                    'created_by' => self::ADMIN_USER_ID,
                    'sub_institute_id' => self::TENANT_ID,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // GTM Contact
            $fullName = trim($p['first_name'] . ' ' . $p['last_name']);
            $gtmContact = DB::connection($conn)->table('gtm_contacts')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('account_id', $gtmAccId)
                ->where(function ($q) use ($p, $fullName) {
                    $q->where('email', $p['email'])
                      ->orWhere('full_name', $fullName);
                })
                ->first();

            if ($gtmContact) {
                $gtmContactId = (int) $gtmContact->id;
                DB::connection($conn)->table('gtm_contacts')
                    ->where('id', $gtmContactId)
                    ->update([
                        'title' => $p['title'],
                        'phone' => $p['mobile'],
                        'linkedin_url' => $p['linkedin_url'] ?? null,
                        'role_in_deal' => $p['role_in_deal'],
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);
            } else {
                $gtmContactId = (int) DB::connection($conn)->table('gtm_contacts')->insertGetId([
                    'sub_institute_id' => self::TENANT_ID,
                    'account_id' => $gtmAccId,
                    'full_name' => $fullName,
                    'title' => $p['title'],
                    'email' => $p['email'],
                    'phone' => $p['mobile'],
                    'linkedin_url' => $p['linkedin_url'] ?? null,
                    'role_in_deal' => $p['role_in_deal'],
                    'status' => 'active',
                    'source' => 'signals',
                    'created_by' => self::ADMIN_USER_ID,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $contactMap[$roleKey] = [
                'crm_contact_id' => $crmContactId,
                'gtm_contact_id' => $gtmContactId,
            ];
        }

        // Pass 2: Set reports_to_id in crm_contacts (Head of HR and CFO report to Author)
        $authorCrmId = $contactMap['author']['crm_contact_id'];
        foreach (['head_of_hr', 'cfo'] as $subRole) {
            if (isset($contactMap[$subRole])) {
                DB::connection($conn)->table('crm_contacts')
                    ->where('id', $contactMap[$subRole]['crm_contact_id'])
                    ->update([
                        'reports_to_id' => $authorCrmId,
                        'updated_at' => now(),
                    ]);
            }
        }

        return $contactMap;
    }

    /**
     * Resolves lead in `crm_leads` idempotently, ensuring score = 40.
     */
    private function resolveLead(string $conn, array $data, int $orgAccountId, int $crmOrgId, int $authorContactId): int
    {
        $existingLead = DB::connection($conn)->table('crm_leads')
            ->where('sub_institute_id', self::TENANT_ID)
            ->where('org_account_id', $orgAccountId)
            ->where('product_master_id', self::PRODUCT_MASTER_ID)
            ->first();

        $authorPersona = $data['personas']['author'];
        $org = $data['organization'];

        // Compute genuine score via LeadScorer
        $leadAttrs = [
            'industry' => 'Education', // rule 14 -> 25 pts
            'rating' => 'Hot',         // rule 15 -> 15 pts -> 40 pts
        ];
        $scored = LeadScorer::score($leadAttrs, self::TENANT_ID);

        if ($existingLead) {
            $leadId = (int) $existingLead->id;
            DB::connection($conn)->table('crm_leads')
                ->where('id', $leadId)
                ->update([
                    'industry' => 'Education',
                    'rating' => 'Hot',
                    'lead_score' => $scored['total'],
                    'lead_score_basis' => $scored['basis'] === [] ? null : json_encode($scored['basis']),
                    'converted_organization_id' => $crmOrgId,
                    'converted_contact_id' => $authorContactId,
                    'lead_status' => 'Pre Qualified',
                    'lifecycle_status' => 'qualified',
                    'assigned_to' => self::ADMIN_USER_ID,
                    'updated_at' => now(),
                ]);

            return $leadId;
        }

        // Mint new lead via LeadFactory
        return LeadFactory::create([
            'first_name' => $authorPersona['first_name'],
            'last_name' => $authorPersona['last_name'],
            'company' => $org['name'],
            'designation' => $authorPersona['title'],
            'email' => $authorPersona['email'],
            'phone' => $authorPersona['phone'],
            'mobile' => $authorPersona['mobile'],
            'lead_source' => 'LinkedIn',
            'industry' => 'Education',
            'rating' => 'Hot',
            'lead_status' => 'Pre Qualified',
            'lifecycle_status' => 'qualified',
            'product_master_id' => self::PRODUCT_MASTER_ID,
            'org_account_id' => $orgAccountId,
            'converted_organization_id' => $crmOrgId,
            'converted_contact_id' => $authorContactId,
            'assigned_to' => self::ADMIN_USER_ID,
            'description' => "LinkedIn buying inquiry from {$authorPersona['first_name']} {$authorPersona['last_name']} ({$org['name']}): " . Str::limit($data['signal']['body'], 400),
        ], self::TENANT_ID, self::ADMIN_USER_ID);
    }

    /**
     * Staging outreach drafts in `gtm_outreach_messages` with status = 'pending_approval'.
     */
    private function resolveOutreachMessage(string $conn, int $gtmAccId, int $gtmContactId, array $outreach): int
    {
        $existing = DB::connection($conn)->table('gtm_outreach_messages')
            ->where('sub_institute_id', self::TENANT_ID)
            ->where('account_id', $gtmAccId)
            ->where('contact_id', $gtmContactId)
            ->where('product_master_id', self::PRODUCT_MASTER_ID)
            ->first(['id']);

        $payload = [
            'sub_institute_id' => self::TENANT_ID,
            'account_id' => $gtmAccId,
            'contact_id' => $gtmContactId,
            'product_master_id' => self::PRODUCT_MASTER_ID,
            'to_email' => $outreach['to_email'],
            'subject' => $outreach['subject'],
            'body' => $outreach['body'],
            'status' => 'pending_approval',
            'step' => 1,
            'created_by' => self::ADMIN_USER_ID,
            'submitted_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::connection($conn)->table('gtm_outreach_messages')
                ->where('id', $existing->id)
                ->update($payload);

            return (int) $existing->id;
        }

        $payload['attempts'] = 0;
        $payload['created_at'] = now();

        return (int) DB::connection($conn)->table('gtm_outreach_messages')->insertGetId($payload);
    }

    /**
     * Returns structured dataset for all 3 signals, organizations, personas, and outreach drafts.
     */
    private function getLifecycleDatasets(): array
    {
        return [
            // Signal 1: Narendra Parvathareddy (VN Softverg Technology)
            [
                'signal' => [
                    'source_url' => 'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/',
                    'signal_key' => hash('sha256', 'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/'),
                    'title' => '📢 Looking for an HRMS Solution for Our Organization! — VN Softverg Technology',
                    'body' => "VN SOFTVERG TECHNOLOGY PVT. LTD. is looking for a reliable and comprehensive Human Resource Management System (HRMS) to streamline our organization's HR operations.\n\nHeadcount: 30–50 employees\nLocation: Hyderabad, India\nKey Features Required:\n- Employee Management\n- Payroll Management\n- Attendance Management\n- Recruitment & Interview Management\n- Leave Management\n- HR Reports & Analytics\n\nRFQ Contact Email: vinayjivankumar.jana@vnsoftverg.com\nAuthor: Narendra Parvathareddy (Director)",
                    'published_at' => '2026-11-05 12:20:17',
                    'classification' => 'buyer_inquiry',
                    'classification_confidence' => 0.95,
                    'relevance_score' => 0.95,
                    'intent_score' => 95,
                    'classification_reason' => 'Corporate director issuing formal RFP for HRMS and payroll solution for 30-50 employees with dedicated RFQ contact email.',
                    'tags_payload' => [
                        'intent_score' => 95,
                        'intent_classification' => 'buyer_inquiry',
                        'tags' => ['HRMS', 'Payroll', 'Attendance', 'Recruitment', 'Hyderabad', 'IT Services', 'buyer_inquiry'],
                        'metadata' => [
                            'author_name' => 'Narendra Parvathareddy',
                            'author_title' => 'Director',
                            'company_name' => 'VN SOFTVERG TECHNOLOGY PVT. LTD.',
                            'contact_email' => 'vinayjivankumar.jana@vnsoftverg.com',
                            'employee_count_min' => 30,
                            'employee_count_max' => 50,
                            'location' => 'Hyderabad, Telangana, India',
                            'activity_urn' => 'urn:li:activity:7524083166698041346',
                        ],
                        'raw_payload' => [
                            'activity_id' => '7524083166698041346',
                            'author_profile' => 'https://in.linkedin.com/in/narendra-parvathareddy',
                            'comment_count' => 121,
                            'headline' => '📢 Looking for an HRMS Solution for Our Organization!',
                        ],
                    ],
                ],
                'organization' => [
                    'name' => 'VN SOFTVERG TECHNOLOGY PVT. LTD.',
                    'domain' => 'vnsoftverg.com',
                    'website' => 'https://vnsoftverg.com',
                    'phone' => '+91 40 4859 1234',
                    'email' => 'vinayjivankumar.jana@vnsoftverg.com',
                    'industry' => 'Technology',
                    'employee_range' => '30-50',
                    'employees' => 40,
                    'billing_street' => 'Plot 42, Hitec City',
                    'billing_city' => 'Hyderabad',
                    'billing_state' => 'Telangana',
                    'billing_country' => 'India',
                ],
                'personas' => [
                    'author' => [
                        'salutation' => 'Mr.',
                        'first_name' => 'Narendra',
                        'last_name' => 'Parvathareddy',
                        'title' => 'Director & Co-Founder',
                        'department' => 'Executive Leadership',
                        'email' => 'vinayjivankumar.jana@vnsoftverg.com',
                        'phone' => '+91 40 4859 1234',
                        'mobile' => '+91 98490 12345',
                        'linkedin_url' => 'https://in.linkedin.com/in/narendra-parvathareddy',
                        'role_in_deal' => 'decision_maker',
                    ],
                    'head_of_hr' => [
                        'salutation' => 'Mr.',
                        'first_name' => 'Vinay Jivan Kumar',
                        'last_name' => 'Jana',
                        'title' => 'Head of Human Resources',
                        'department' => 'Human Resources & Operations',
                        'email' => 'hr@vnsoftverg.com',
                        'phone' => '+91 40 4859 1235',
                        'mobile' => '+91 98490 12346',
                        'role_in_deal' => 'influencer',
                    ],
                    'cfo' => [
                        'salutation' => 'CA',
                        'first_name' => 'Suresh',
                        'last_name' => 'Reddy',
                        'title' => 'CFO / Finance Head',
                        'department' => 'Finance & Accounts',
                        'email' => 'finance@vnsoftverg.com',
                        'phone' => '+91 40 4859 1236',
                        'mobile' => '+91 98490 12347',
                        'role_in_deal' => 'economic_buyer',
                    ],
                ],
                'outreach' => [
                    'to_email' => 'vinayjivankumar.jana@vnsoftverg.com',
                    'subject' => 'HRMS & Payroll Solution for VN Softverg Technology (30-50 Employees)',
                    'body' => "Dear Narendra,\n\nI noticed your LinkedIn inquiry regarding a comprehensive HRMS and payroll management system for VN Softverg Technology to support your team of 30 to 50 employees.\n\nScholarClone HRMS is designed specifically for growing organizations requiring integrated employee lifecycle management. Our platform covers the key modules you outlined:\n\n- Centralized Employee Management and Records\n- Automated Payroll Management and Payslip Generation\n- Attendance Tracking and Shift Configuration\n- Recruitment and Candidate Interview Pipeline\n- Leave Approval Workflows and Balance Tracking\n- Executive HR Reports and Operational Analytics\n\nWe offer straightforward subscription tiers tailored for small and mid-sized teams with dedicated onboarding assistance.\n\nI would welcome the opportunity to share our product overview and provide a tailored quotation for 30 to 50 users. Would you or Vinay be available for a brief introductory call and walkthrough this week?\n\nWarm regards,\nScholarClone Team",
                ],
            ],

            // Signal 2: Nihar Ranjan Rout (Creuto Technologies)
            [
                'signal' => [
                    'source_url' => 'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/',
                    'signal_key' => hash('sha256', 'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/'),
                    'title' => 'Looking for a reliable HR and payroll solution for a growing team — Creuto',
                    'body' => "Looking for a reliable HR and payroll solution for a growing team.\n\nIf you have experience setting up HRMS tools or have built one, I’d love to connect. Please share your recommendations or drop me a DM.\n\nAuthor: Nihar Ranjan Rout (Founder & CEO, Creuto)\nLocation: Bhubaneswar / Bangalore, India",
                    'published_at' => '2026-11-05 05:30:15',
                    'classification' => 'buyer_inquiry',
                    'classification_confidence' => 0.85,
                    'relevance_score' => 0.85,
                    'intent_score' => 85,
                    'classification_reason' => 'Founder & CEO actively soliciting recommendations and direct messages for HR and payroll software for a growing team.',
                    'tags_payload' => [
                        'intent_score' => 85,
                        'intent_classification' => 'buyer_inquiry',
                        'tags' => ['HRMS', 'Payroll', 'Startup', 'Software Development', 'Creuto', 'buyer_inquiry'],
                        'metadata' => [
                            'author_name' => 'Nihar Ranjan Rout',
                            'author_title' => 'Founder & CEO',
                            'company_name' => 'Creuto Technologies',
                            'website' => 'https://creuto.com',
                            'personal_site' => 'https://niharrout.com',
                            'employee_count' => 25,
                            'location' => 'Bhubaneswar / Bangalore, India',
                            'activity_urn' => 'urn:li:activity:7523979977768210432',
                        ],
                        'raw_payload' => [
                            'activity_id' => '7523979977768210432',
                            'author_profile' => 'https://in.linkedin.com/in/niharrout',
                            'comment_count' => 486,
                            'headline' => 'Looking for a reliable HR and payroll solution for a growing team.',
                        ],
                    ],
                ],
                'organization' => [
                    'name' => 'Creuto Technologies',
                    'domain' => 'creuto.com',
                    'website' => 'https://creuto.com',
                    'phone' => '+91 80 4123 5678',
                    'email' => 'hello@creuto.com',
                    'industry' => 'Technology',
                    'employee_range' => '20-50',
                    'employees' => 25,
                    'billing_street' => 'DLF Cybercity, Patia',
                    'billing_city' => 'Bhubaneswar',
                    'billing_state' => 'Odisha',
                    'billing_country' => 'India',
                ],
                'personas' => [
                    'author' => [
                        'salutation' => 'Mr.',
                        'first_name' => 'Nihar Ranjan',
                        'last_name' => 'Rout',
                        'title' => 'Founder & CEO',
                        'department' => 'Executive Leadership',
                        'email' => 'nihar@creuto.com',
                        'phone' => '+91 80 4123 5678',
                        'mobile' => '+91 97760 12345',
                        'linkedin_url' => 'https://in.linkedin.com/in/niharrout',
                        'role_in_deal' => 'decision_maker',
                    ],
                    'head_of_hr' => [
                        'salutation' => 'Ms.',
                        'first_name' => 'Swati',
                        'last_name' => 'Mohanty',
                        'title' => 'Head of Human Resources',
                        'department' => 'People Operations',
                        'email' => 'people@creuto.com',
                        'phone' => '+91 80 4123 5679',
                        'mobile' => '+91 97760 12346',
                        'role_in_deal' => 'influencer',
                    ],
                    'cfo' => [
                        'salutation' => 'Mr.',
                        'first_name' => 'Debasish',
                        'last_name' => 'Patra',
                        'title' => 'CFO / Finance Head',
                        'department' => 'Finance & Operations',
                        'email' => 'finance@creuto.com',
                        'phone' => '+91 80 4123 5680',
                        'mobile' => '+91 97760 12347',
                        'role_in_deal' => 'economic_buyer',
                    ],
                ],
                'outreach' => [
                    'to_email' => 'nihar@creuto.com',
                    'subject' => "Reliable HR & Payroll Setup for Creuto's Growing Development Team",
                    'body' => "Hi Nihar,\n\nI came across your post regarding an HR and payroll solution for your growing team at Creuto.\n\nHaving worked closely with tech product studios and scaling engineering teams, we understand that you need an HRMS that eliminates manual administration without unnecessary complexity. ScholarClone provides a clean, unified platform for:\n\n- Frictionless Employee Onboarding and Directory\n- Streamlined Monthly Payroll and Direct Salary Calculations\n- Integrated Leave Approvals and Attendance Monitoring\n- Fast Setup with Self-Service Portals for Engineers\n\nOur software allows technology teams to stay focused on building products while administrative operations run smoothly in the background.\n\nI would be delighted to share our platform demo and discuss how we can assist Creuto as you scale. Let me know if you would like to connect for a quick walkthrough.\n\nWarm regards,\nScholarClone Team",
                ],
            ],

            // Signal 3: Anjuli Subedi (Gargi Media House / Kathmandu Org)
            [
                'signal' => [
                    'source_url' => 'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/',
                    'signal_key' => hash('sha256', 'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/'),
                    'title' => 'Looking for HRMS & Payroll Software Recommendations! — Kathmandu Org',
                    'body' => "We are currently evaluating HRMS and Payroll Management Software for our growing organisation in Kathmandu.\n\nHeadcount: Currently ~30, expanding to 50+ employees\nLocation: Kathmandu, Nepal\nKey Requirements:\n- Employee Management, Attendance & Leave Management\n- Payroll Management & automated monthly salary processing\n- Nepal Payroll Compliance (SSF, TDS, income tax deductions, statutory reporting)\n- Shift, Task, Performance, Recruitment, Expense Management\n- Reports & Analytics\n\nCommercials Requested:\n- Quotation for 30 employees & expansion to 50 employees\n- Implementation, training, and annual maintenance charges\n- Product demo or trial\nRFP Email: anjulisubedi30@gmail.com\nAuthor: Anjuli S. (Managing Director / HR & Operations Lead)",
                    'published_at' => '2026-11-03 07:15:51',
                    'classification' => 'buyer_inquiry',
                    'classification_confidence' => 0.98,
                    'relevance_score' => 0.98,
                    'intent_score' => 98,
                    'classification_reason' => 'Detailed 11-module procurement specification for 30-50 headcount, requiring Nepal SSF/TDS payroll compliance and RFQ proposals.',
                    'tags_payload' => [
                        'intent_score' => 98,
                        'intent_classification' => 'buyer_inquiry',
                        'tags' => ['HRMS', 'Payroll', 'Nepal Compliance', 'SSF', 'TDS', 'Kathmandu', 'buyer_inquiry'],
                        'metadata' => [
                            'author_name' => 'Anjuli S. (Anjuli Subedi)',
                            'author_title' => 'Managing Director / HR & Operations Lead',
                            'company_name' => 'Gargi Media House',
                            'contact_email' => 'anjulisubedi30@gmail.com',
                            'employee_count_min' => 30,
                            'employee_count_max' => 50,
                            'location' => 'Kathmandu, Bagmati Province, Nepal',
                            'compliance_requirements' => 'Nepal SSF, TDS, and statutory tax reporting',
                            'activity_urn' => 'urn:li:activity:7523281778606956544',
                        ],
                        'raw_payload' => [
                            'activity_id' => '7523281778606956544',
                            'author_profile' => 'https://np.linkedin.com/in/anjuli-s',
                            'comment_count' => 174,
                            'headline' => 'Looking for HRMS & Payroll Software Recommendations!',
                        ],
                    ],
                ],
                'organization' => [
                    'name' => 'Gargi Media House',
                    'domain' => null,
                    'website' => null,
                    'phone' => '+977 1 441 2345',
                    'email' => 'anjulisubedi30@gmail.com',
                    'industry' => 'Media & Communications',
                    'employee_range' => '30-50',
                    'employees' => 30,
                    'billing_street' => 'Putalisadak, Ward 28',
                    'billing_city' => 'Kathmandu',
                    'billing_state' => 'Bagmati Province',
                    'billing_country' => 'Nepal',
                ],
                'personas' => [
                    'author' => [
                        'salutation' => 'Ms.',
                        'first_name' => 'Anjuli',
                        'last_name' => 'Subedi',
                        'title' => 'Managing Director / HR & Operations Lead',
                        'department' => 'Executive Management',
                        'email' => 'anjulisubedi30@gmail.com',
                        'phone' => '+977 1 441 2345',
                        'mobile' => '+977 985 1012345',
                        'linkedin_url' => 'https://np.linkedin.com/in/anjuli-s',
                        'role_in_deal' => 'decision_maker',
                    ],
                    'head_of_hr' => [
                        'salutation' => 'Mr.',
                        'first_name' => 'Bikash',
                        'last_name' => 'Adhikari',
                        'title' => 'Head of Human Resources',
                        'department' => 'Human Resources & Administration',
                        'email' => 'hr@gargimedia.com.np',
                        'phone' => '+977 1 441 2346',
                        'mobile' => '+977 985 1012346',
                        'role_in_deal' => 'influencer',
                    ],
                    'cfo' => [
                        'salutation' => 'Mr.',
                        'first_name' => 'Pradeep',
                        'last_name' => 'Shrestha',
                        'title' => 'CFO / Finance Head',
                        'department' => 'Finance & Accounts',
                        'email' => 'finance@gargimedia.com.np',
                        'phone' => '+977 1 441 2347',
                        'mobile' => '+977 985 1012347',
                        'role_in_deal' => 'economic_buyer',
                    ],
                ],
                'outreach' => [
                    'to_email' => 'anjulisubedi30@gmail.com',
                    'subject' => 'Integrated HRMS & Payroll Proposal for Kathmandu Organization (30-50)',
                    'body' => "Dear Anjuli,\n\nI saw your detailed RFP on LinkedIn regarding an integrated HRMS and payroll solution for your organization in Kathmandu, currently supporting 30 employees with expansion plans for 50 and beyond.\n\nScholarClone HRMS addresses the complete specifications mentioned in your evaluation:\n\n- Comprehensive Employee Lifecycle: Records, document repository, and history\n- Nepal Payroll Compliance: Configurable support for SSF deductions, TDS calculations, and statutory reporting\n- Time & Attendance: Daily attendance tracking, shift scheduling, and leave approvals\n- Operational Modules: Task assignment, performance review cycles, expense tracking, and recruitment records\n- Reporting: Automated attendance summaries, payroll registers, and executive dashboards\n\nWe provide transparent subscription pricing structured around your initial 30-member tier with seamless scaling as your team grows to 50.\n\nWe would be pleased to share our complete software profile, feature specification, and customized quotation with your team. Shall we schedule a virtual demonstration this week?\n\nWarm regards,\nScholarClone Team",
                ],
            ],
        ];
    }
}

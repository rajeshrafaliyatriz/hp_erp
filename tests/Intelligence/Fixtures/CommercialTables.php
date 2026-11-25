<?php

namespace Tests\Intelligence\Fixtures;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\RightsChecker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal in-memory SQLite shapes of the commercial tables (only the columns the detectors read) plus row helpers. No data is copied from anywhere; these
 * tables exist only inside the isolated test process (see IntelligenceTestCase).
 */
trait CommercialTables
{
    protected function createCommercialTables(): void
    {
        Schema::create('g2g_company_opportunities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('fetch_level', 20)->nullable();
            $t->string('claim_level', 20)->nullable();
            $t->string('qualification', 40)->nullable();
            $t->date('event_date')->nullable();
            $t->date('source_published_at')->nullable();
            $t->timestamp('first_discovered_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->string('review_status', 12)->nullable()->default('New');
            $t->string('import_source', 100)->nullable();
            $t->boolean('is_sample')->default(false);
        });
        Schema::create('g2g_research_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->date('report_date');
            $t->string('status', 20);
            $t->unsignedSmallInteger('opportunities_qualified')->nullable()->default(0);
            $t->unsignedSmallInteger('queries_failed')->default(0);
        });
        Schema::create('gtm_deals', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('stage', 20)->default('discovery');
            $t->decimal('amount', 14, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->date('expected_close_date')->nullable();
            $t->text('next_step')->nullable();
            $t->date('next_step_due')->nullable();
            $t->timestamp('stage_changed_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('gtm_activities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->string('type', 20);
            $t->timestamp('occurred_at')->nullable();
        });
        Schema::create('gtm_contacts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id');
            $t->string('role_in_deal', 30)->nullable();
            $t->softDeletes();
        });
        Schema::create('gtm_renewals', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id');
            $t->date('renewal_date');
            $t->string('status', 12)->default('upcoming');
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('gtm_accounts', function (Blueprint $t) {
            $t->unsignedBigInteger('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('crm_organization_id')->nullable();   // 2026_11_24_081100: the GTM <-> CRM link read by the cross-functional detector
            $t->softDeletes();
            $t->primary(['id', 'sub_institute_id']);   // ids may collide across tenants in tests
        });
        Schema::create('gtm_health_snapshots', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id');
            $t->date('snapshot_date');
            $t->unsignedTinyInteger('score')->nullable();
            $t->string('band', 12);
        });
        Schema::create('crm_opportunities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('sales_stage', 100)->nullable();
            $t->date('closing_date')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('crm_invoices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('organization_id')->nullable();   // 2026_11_24_082800: the organisation the invoice is for
            $t->string('invoice_stage', 100)->nullable();
            $t->date('due_date')->nullable();
            $t->decimal('total', 15, 2)->default(0);
            $t->decimal('amount_paid', 15, 2)->default(0);
            $t->string('currency', 10)->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->softDeletes();
        });
    }

    /** A caller for $tenant holding view on exactly the given menu links (no role-based grant). */
    protected function commercialCaller(string $role, array $links = [], int $tenant = 1, int $user = 10): IntelligenceCaller
    {
        $rights = new class($links) implements RightsChecker {
            public function __construct(private array $links)
            {
            }

            public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool
            {
                return $action === 'view' && in_array($accessLink, $this->links, true);
            }
        };

        return new IntelligenceCaller($tenant, $user, 7, $role, null, $rights);
    }

    protected function insertRow(string $table, array $row): void
    {
        $this->db()->table($table)->insert($row);
    }

    protected function addSignal(int $tenant, array $o = []): void
    {
        $this->insertRow('g2g_company_opportunities', array_merge(['sub_institute_id' => $tenant, 'first_discovered_at' => '2026-11-10 00:00:00', 'event_date' => '2026-11-01',
            'fetch_level' => 'page_text', 'review_status' => 'Reviewed', 'import_source' => null, 'is_sample' => 0], $o));
    }

    protected function addRun(int $tenant, array $o = []): void
    {
        $this->insertRow('g2g_research_runs', array_merge(['sub_institute_id' => $tenant, 'report_date' => '2026-11-15', 'status' => 'success', 'opportunities_qualified' => 3, 'queries_failed' => 0], $o));
    }

    protected function addDeal(int $tenant, array $o = []): void
    {
        $this->insertRow('gtm_deals', array_merge(['sub_institute_id' => $tenant, 'account_id' => 1, 'stage' => 'proposal', 'amount' => 100, 'currency' => 'USD', 'expected_close_date' => '2026-12-30',
            'next_step' => 'call', 'next_step_due' => '2026-12-01', 'stage_changed_at' => '2026-11-15 00:00:00', 'created_at' => '2026-10-01 00:00:00'], $o));
    }

    protected function addCrmOpp(int $tenant, array $o = []): void
    {
        $this->insertRow('crm_opportunities', array_merge(['sub_institute_id' => $tenant, 'sales_stage' => 'Prospecting', 'closing_date' => '2026-12-30', 'updated_at' => '2026-11-20 00:00:00'], $o));
    }

    protected function addInvoice(int $tenant, array $o = []): void
    {
        $this->insertRow('crm_invoices', array_merge(['sub_institute_id' => $tenant, 'due_date' => '2026-12-15', 'total' => 100, 'amount_paid' => 0, 'currency' => 'USD'], $o));
    }
}

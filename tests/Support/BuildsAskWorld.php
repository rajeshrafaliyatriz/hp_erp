<?php

namespace Tests\Support;

use App\Domain\Ask\AskRecords;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database and the people the Ask chat tests (slice 8) run on: the agent world (BuildsAgentWorld: every table from its REAL migration file on in-memory
 * sqlite, real users and rights rows, the fake model priced by the real meter) plus what Ask reads that the agent world does not build: the leads table, the
 * menu rows of the record screens, and the two Ask migrations run from their real files. The model is the same FakeAgentModel; `askModel()` makes it answer
 * the planner and the answerer separately, and every call it receives is kept in `$this->model->calls` so a test can read the exact prompt.
 *
 * Call buildAskWorld() in setUp().
 */
trait BuildsAskWorld
{
    use BuildsAgentWorld;

    protected const ASK_LINK = '/module/crm/marketing/campaigns';

    protected const LEADS_LINK = '/module/crm/marketing/leads';

    protected const CONTACTS_LINK = '/module/crm/marketing/contacts';

    protected const CONTACTS_SUPPORT_LINK = '/module/crm/support/contacts';

    protected const OPPS_LINK = '/module/crm/sales/opportunities';

    protected const PRODUCTS_LINK = '/module/crm/sales/products';

    protected const SIGNALS_RIGHT_LINK = '/signals';

    protected function buildAskWorld(): void
    {
        $this->buildAgentWorld();

        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->string('lead_no', 100)->nullable();
            $t->string('salutation', 20)->nullable();
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('company', 191)->nullable();
            $t->string('designation', 191)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('secondary_email', 191)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('mobile', 50)->nullable();
            $t->string('lead_source', 191)->nullable();
            $t->string('industry', 191)->nullable();
            $t->string('lead_status', 191)->nullable();
            $t->string('lifecycle_status', 50)->nullable();
            $t->text('next_action')->nullable();
            $t->timestamp('follow_up_due_date')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('rating', 191)->nullable();
            $t->integer('lead_score')->default(0);
            $t->boolean('converted')->default(false);
            $t->unsignedBigInteger('source_company_opportunity_id')->nullable();
            $t->string('city', 191)->nullable();
            $t->string('state', 191)->nullable();
            $t->string('country', 191)->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();   // a legacy lead can have no organisation: it is never anybody's
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        foreach (['2026_12_11_120000_create_g2g_agent_conversations_table', '2026_12_11_120100_create_g2g_agent_messages_table'] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 310, 'menu_name' => 'Campaigns', 'access_link' => self::ASK_LINK],
            ['id' => 311, 'menu_name' => 'Leads', 'access_link' => self::LEADS_LINK],
            ['id' => 312, 'menu_name' => 'Contacts', 'access_link' => self::CONTACTS_LINK],
            ['id' => 313, 'menu_name' => 'Contacts (support)', 'access_link' => self::CONTACTS_SUPPORT_LINK],
            ['id' => 314, 'menu_name' => 'Opportunities & Deals', 'access_link' => self::OPPS_LINK],
            ['id' => 315, 'menu_name' => 'Products', 'access_link' => self::PRODUCTS_LINK],
            ['id' => 316, 'menu_name' => 'Company Opportunities', 'access_link' => self::SIGNALS_RIGHT_LINK],
        ]);
        Cache::flush();
        AskRecords::forgetSchema();
        ProductCatalog::forgetCustom();
        config(['signals.permissions.use_menu_rights' => false]);
    }

    // ---- people ----

    /** Every right a person needs to ask about every kind of record: the chat's own row and view on each record screen. The Signals right is the ROLE (hr_manager) while the flag is off. */
    protected function allReadRights(): array
    {
        return [
            self::ASK_LINK => ['view'], self::LEADS_LINK => ['view'], self::CONTACTS_LINK => ['view'], self::ORG_LINK => ['view'], self::OPPS_LINK => ['view'], self::PRODUCTS_LINK => ['view'],
        ];
    }

    /**
     * Someone who may ask and read everything Ask can read (signals through the hr_manager role, which `ModuleGate::signals(..., 'read')` lets in while the menu
     * rights for Signals are off). Pass `$rights` to give exactly those instead, and `$role` to change the profile's role.
     *
     * @param  ?array<string, list<string>>  $rights
     * @return array{id: int, h: array<string, string>}
     */
    protected function asker(int $tenant = self::T, ?array $rights = null, string $role = 'hr_manager', string $first = 'Asha', string $last = 'Rao'): array
    {
        return $this->userWithRole($role, $rights ?? $this->allReadRights(), $tenant, $first, $last);
    }

    /** The AskCaller of a person made by user() / asker(), for tests that call a tool directly. @param array{id: int, h: array<string, string>} $user */
    protected function callerOf(array $user, ?int $tenant = null): \App\Domain\Ask\AskCaller
    {
        $row = DB::table('tbluser')->where('id', $user['id'])->first();

        return new \App\Domain\Ask\AskCaller($tenant ?? (int) $row->sub_institute_id, (int) $row->id, (int) $row->user_profile_id);
    }

    /** Runs one lookup the way the planner's call would be run: validated, then run. @param array<string, mixed> $args */
    protected function lookup(\App\Domain\Ask\AskCaller $caller, string $tool, array $args = []): \App\Domain\Ask\ToolResult
    {
        $t = (new \App\Domain\Ask\AskToolRegistry())->find($tool);

        return $t->run($caller, $t->validate($caller, $args));
    }

    // ---- the model ----

    /**
     * Makes the fake model answer the PLANNER with `$plan` and the ANSWERER with `$answer`: each an array (encoded as JSON), a string (returned as it is, to
     * test a broken answer) or a closure that receives the messages. The two are told apart by the first words of the system message.
     *
     * @param  array<string, mixed>|string|\Closure  $plan
     * @param  array<string, mixed>|string|\Closure  $answer
     */
    protected function askModel(array|string|\Closure $plan, array|string|\Closure $answer = ['statements' => []]): void
    {
        $this->model->respond = function (array $messages) use ($plan, $answer) {
            $step = $this->isPlanner($messages) ? $plan : $answer;

            return $step instanceof \Closure ? $step($messages) : $step;
        };
    }

    /** @param list<array{role: string, content: string}> $messages */
    protected function isPlanner(array $messages): bool
    {
        return str_starts_with($messages[0]['content'], 'You plan the lookups');
    }

    /** @return list<array{role: string, content: string}> the messages of the n-th planner call (0-based) */
    protected function plannerCall(int $n = 0): array
    {
        return array_values(array_map(fn ($c) => $c['messages'], array_filter($this->model->calls, fn ($c) => $this->isPlanner($c['messages']))))[$n];
    }

    /** @return list<array{role: string, content: string}> the messages of the n-th answerer call (0-based) */
    protected function answererCall(int $n = 0): array
    {
        return array_values(array_map(fn ($c) => $c['messages'], array_filter($this->model->calls, fn ($c) => ! $this->isPlanner($c['messages']))))[$n];
    }

    // ---- data ----

    /** @param array<string, mixed> $o */
    protected function lead(int $tenant, string $first, string $last, array $o = []): int
    {
        return (int) DB::table('crm_leads')->insertGetId($o + [
            'lead_no' => 'LEA-' . strtoupper(substr(uniqid(), -8)), 'first_name' => $first, 'last_name' => $last, 'company' => 'Acme School', 'lead_status' => 'New', 'lead_source' => 'Web',
            'email' => strtolower($first) . '.' . strtolower($last) . '@example.test', 'phone' => '+91 90000 00000', 'sub_institute_id' => $tenant, 'converted' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $o */
    protected function contact(int $tenant, string $first, string $last, ?int $orgId = null, array $o = []): int
    {
        return (int) DB::table('crm_contacts')->insertGetId($o + [
            'contact_no' => 'CON-' . strtoupper(substr(uniqid(), -8)), 'first_name' => $first, 'last_name' => $last, 'organization_id' => $orgId, 'title' => 'Principal',
            'email' => strtolower($first) . '@example.test', 'mobile' => '+91 91111 11111', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $o */
    protected function opportunity(int $tenant, string $name, ?int $orgId = null, array $o = []): int
    {
        return (int) DB::table('crm_opportunities')->insertGetId($o + [
            'opportunity_no' => 'OPP-' . strtoupper(substr(uniqid(), -8)), 'name' => $name, 'organization_id' => $orgId, 'sales_stage' => 'Proposal', 'amount' => 250000, 'currency' => 'RUP',
            'closing_date' => '2026-12-15', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function note(string $table, string $pointer, int $id, int $tenant, string $text): void
    {
        DB::table($table)->insert([$pointer => $id, 'sub_institute_id' => $tenant, 'comment' => $text, 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A planner answer asking for the given lookups: `plan([['search_records', ['text' => 'Tapovan']], ...])`. @param list<array{0: string, 1: array<string, mixed>}> $calls @return array<string, mixed> */
    protected function plan(array $calls): array
    {
        return ['calls' => array_map(fn ($c) => ['tool' => $c[0], 'args' => $c[1]], $calls)];
    }

    /** An answerer answer: `says([['Tapovan has one open deal.', ['opportunity:5']]])`. @param list<array{0: string, 1: list<string>}> $statements @return array<string, mixed> */
    protected function says(array $statements): array
    {
        return ['statements' => array_map(fn ($s) => ['text' => $s[0], 'cite' => array_map(function ($ref) {
            [$type, $id] = explode(':', $ref, 2);

            return ['type' => $type, 'id' => $id];
        }, $s[1])], $statements)];
    }
}

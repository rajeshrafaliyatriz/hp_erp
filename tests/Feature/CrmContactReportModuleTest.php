<?php

namespace Tests\Feature;

use App\Domain\Reports\Crm\CrmReportModules;
use App\Domain\Reports\ReportModuleRegistry;
use App\Domain\Reports\ReportQueryExecutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsContactWorld;
use Tests\TestCase;

/**
 * The Contacts report module knows the people of a company (S18): the fields is_primary, contact_role and organization_name exist, and a tabular report that selects them runs and answers the
 * right values (the company's name through a join of its own, the star as 0/1, the role, and a person with no company still listed).
 */
class CrmContactReportModuleTest extends TestCase
{
    use BuildsContactWorld;

    public function test_the_module_declares_the_three_fields_and_the_company_join(): void
    {
        $module = collect(CrmReportModules::definitions())->firstWhere('key', 'crm.contacts');

        foreach (['is_primary' => 'ct.is_primary', 'contact_role' => 'ct.contact_role', 'organization_name' => 'org_co.name'] as $field => $column) {
            $this->assertTrue($module->hasField($field), "crm.contacts has the field {$field}");
            $this->assertSame($column, $module->field($field)->column);
        }
        $this->assertContains('org_co', array_column($module->alwaysJoins, 'alias'), 'the company\'s name comes through a join of its own, not the `org` of the Organizations module');
        $this->assertSame('contact_role', $module->field('contact_role')->picklistFieldKey);
        $this->assertNotContains('org', array_column($module->alwaysJoins, 'alias'));
    }

    public function test_a_tabular_report_over_contacts_answers_company_star_and_role(): void
    {
        $this->buildContactWorld();
        Schema::create('tblcustom_fields', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('module')->nullable();
            $t->string('field_name')->nullable();
            $t->string('field_label')->nullable();
            $t->string('field_type')->nullable();
            $t->integer('status')->default(1);
        });
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $this->makeContact(self::T, ['organization_id' => $acme, 'last_name' => 'Starred', 'is_primary' => 1, 'contact_role' => 'Billing']);
        $this->makeContact(self::T, ['last_name' => 'Lonely']);
        $this->makeContact(self::T2, ['organization_id' => $this->makeOrganization(self::T2, ['name' => 'Theirs']), 'last_name' => 'Hidden']);

        $result = app(ReportQueryExecutionService::class)->execute('tabular', 'crm.contacts', ['columns' => [
            ['module' => 'crm.contacts', 'field' => 'organization_name'], ['module' => 'crm.contacts', 'field' => 'is_primary'], ['module' => 'crm.contacts', 'field' => 'contact_role'],
        ]], self::T, true);

        $rows = $result['rows'] ?? $result['data'] ?? [];
        $byCompany = collect($rows)->mapWithKeys(fn ($r) => [(string) ($r['crm.contacts.organization_name'] ?? '') => [(int) $r['crm.contacts.is_primary'], $r['crm.contacts.contact_role']]]);
        $this->assertSame([1, 'Billing'], $byCompany['Acme']);
        $this->assertSame([0, null], $byCompany['']);
        $this->assertCount(2, $rows, 'only this organisation\'s people');
        $this->assertTrue(app(ReportModuleRegistry::class)->exists('crm.contacts'));
        $this->assertNotNull(DB::table('crm_contacts')->first());
    }
}

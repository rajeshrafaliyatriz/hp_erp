<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use App\Services\Documents\DocumentAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Organisation module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * `organization.employees` and `organization.departments` already live in ModuleDataSourceCatalog; the sources
 * below are the rest of the module's pages: organisation profile, roles, compliance, discipline, readiness
 * gates and the document library.
 */
final class OrganisationSources extends SourceGroup
{
    private const MODULE = 'organizational_management';

    private const BASE = '/module/organizational-management';

    public function definitions(): array
    {
        $limit = self::limitArg();

        return [
            [
                'name' => 'organization.profile',
                'module' => self::MODULE,
                'label' => 'Organisation profile',
                'description' => 'The organisation\'s own profile: legal name, type, industry, headcount, working week, registered address and contact email and website. Registration and tax numbers are left out.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('org_details as o')
                        ->where('o.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['o.id as profile_id', 'o.legal_name', 'o.organization_type', 'o.industry', 'o.employee_count', 'o.work_week', 'o.registered_address', 'o.email', 'o.website'])
                        ->orderBy('o.id');
                },
            ],
            [
                'name' => 'organization.sister_entities',
                'module' => self::MODULE,
                'label' => 'Sister entities',
                'description' => 'The group companies listed under the organisation profile: name, industry, headcount, working week, address and website.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('org_sister_details as s')
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['s.id as entity_id', 's.legal_name', 's.industry', 's.employee_count', 's.work_week', 's.registered_address', 's.website'])
                        ->orderBy('s.legal_name');
                },
            ],
            [
                'name' => 'organization.roles',
                'module' => self::MODULE,
                'label' => 'Roles and permissions',
                'description' => 'Each role (profile) in the organisation with the data it can see, how many people hold it and how many pages it is allowed to open.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('tbluserprofilemaster as p')
                        ->where('p.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('p.deleted_at')
                        ->select(['p.id as profile_id', 'p.name as role', 'p.role_key', 'p.data_scope', 'p.status'])
                        ->selectRaw('(select count(*) from tbluser u where u.user_profile_id = p.id and u.sub_institute_id = p.sub_institute_id and u.deleted_at is null) as people')
                        ->selectRaw('(select count(*) from tblgroupwise_rights_g2g r where r.profile_id = p.id and r.can_view = 1) as pages_it_can_open')
                        ->orderBy('p.sort_order')
                        ->orderBy('p.name');
                },
            ],
            [
                'name' => 'organization.compliance_library',
                'module' => self::MODULE,
                'label' => 'Compliance library',
                'description' => 'The compliance obligations the organisation tracks: name, the standard it comes from, who owns it, due date and how often it repeats.',
                'arguments' => [
                    self::arg('frequency', 'string', 'Only obligations with this frequency as stored, e.g. monthly or yearly.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('master_compliance as mc')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'mc.assigned_to')
                        ->where('mc.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('mc.deleted_at')
                        ->select(['mc.id as compliance_id', 'mc.name', 'mc.standard_name', 'mc.duedate as due_date', 'mc.frequency'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as owner")
                        ->orderBy('mc.duedate');

                    if (($f = $this->text($a, 'frequency')) !== null) {
                        $q->where('mc.frequency', $f);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'organization.disciplinary_records',
                'module' => self::MODULE,
                'label' => 'Disciplinary library',
                'description' => 'Recorded disciplinary incidents: the employee and department, the type of misconduct, when it happened, the action taken and the date it was reported. Incident narratives are left out.',
                'arguments' => [
                    self::arg('department_id', 'integer', 'Only incidents in this department.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('discliplinary_management as dm')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'dm.employee_id')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'dm.department_id')
                        ->where('dm.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('dm.deleted_at')
                        ->select(['dm.id as record_id', 'dm.misconduct_type', 'dm.incident_datetime', 'dm.action_taken', 'dm.date_of_report', 'd.department'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->orderByDesc('dm.incident_datetime');

                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('dm.department_id', $d);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'organization.readiness_gates',
                'module' => self::MODULE,
                'label' => 'Readiness gates',
                'description' => 'The readiness checks that decide when AI features can switch on: each gate\'s state, its current value against the threshold, and what to fix.',
                'arguments' => [
                    self::arg('state', 'string', 'Only gates in this state, e.g. ready or blocked.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('tenant_readiness_gate as g')
                        ->where('g.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['g.gate_key', 'g.state', 'g.unit', 'g.value', 'g.enable_threshold', 'g.remedy', 'g.computed_at'])
                        ->orderBy('g.gate_key');

                    if (($s = $this->text($a, 'state')) !== null) {
                        $q->where('g.state', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'documents.library',
                'module' => self::MODULE,
                'label' => 'Document library',
                'description' => 'Documents the caller is allowed to open: title, type, category, department, folder, document date, size and processing state. Document text is not returned.',
                'arguments' => [
                    self::arg('document_type', 'string', 'Only documents of this type as stored.'),
                    self::arg('department_id', 'integer', 'Only documents of this department.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = $this->visibleDocuments($scope);

                    if (($t = $this->text($a, 'document_type')) !== null) {
                        $q->where('document_type', $t);
                    }
                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('department_id', $d);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'documents.my_department',
                'module' => self::MODULE,
                'label' => 'My department documents',
                'description' => 'Documents of the caller\'s own department that the caller may open: title, type, category, folder, document date and size.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $dept = $this->callerDepartment($scope);

                    return $this->visibleDocuments($scope)->where('department_id', $dept ?? 0);
                },
            ],
        ];
    }

    public function pages(): array
    {
        $b = self::BASE;

        return [
            "$b/organization-setup" => [
                'sources' => ['organization.profile', 'organization.departments', 'documents.my_department'],
                'purpose' => 'The hub for setting up the organisation: its profile, its departments and department documents.',
                'action' => null,
            ],
            "$b/organization-setup/organization-profile" => [
                'sources' => ['organization.profile', 'organization.sister_entities'],
                'purpose' => 'Holds the organisation\'s legal and contact details and its group companies.',
                'action' => null,
            ],
            "$b/organization-setup/department-management" => [
                'sources' => ['organization.departments', 'organization.employees'],
                'purpose' => 'Create and arrange departments, choose their heads and see who sits in each.',
                'action' => 'create_department',
            ],
            "$b/organization-setup/my-department-documents" => [
                'sources' => ['documents.my_department'],
                'purpose' => 'Shows an employee the shared documents of their own department.',
                'action' => null,
            ],
            "$b/user-management" => [
                'sources' => ['organization.employees', 'organization.roles'],
                'purpose' => 'The hub for the people of the organisation and the roles that decide what they can open.',
                'action' => null,
            ],
            "$b/user-management/employee-directory" => [
                'sources' => ['organization.employees', 'organization.departments'],
                'purpose' => 'Lists every employee with job title, department and reporting manager.',
                'action' => null,
            ],
            "$b/user-management/role-and-permissions" => [
                'sources' => ['organization.roles'],
                'purpose' => 'Defines roles and which pages and data each role can reach.',
                'action' => null,
            ],
            "$b/compliance-and-discipline" => [
                'sources' => ['organization.compliance_library', 'organization.disciplinary_records'],
                'purpose' => 'The hub for compliance obligations and disciplinary records.',
                'action' => null,
            ],
            "$b/compliance-and-discipline/compliance-library" => [
                'sources' => ['organization.compliance_library'],
                'purpose' => 'Keeps the register of compliance obligations with owners, due dates and frequency.',
                'action' => null,
            ],
            "$b/compliance-and-discipline/disciplinary-library" => [
                'sources' => ['organization.disciplinary_records'],
                'purpose' => 'Records disciplinary incidents and the action taken on each.',
                'action' => null,
            ],
            "$b/readiness-gates" => [
                'sources' => ['organization.readiness_gates'],
                'purpose' => 'Shows whether the organisation\'s data is complete enough to switch on AI-driven features.',
                'action' => null,
            ],
            '/organization/setup' => [
                'sources' => [],
                'purpose' => 'A guided checklist that walks a new organisation through its first setup steps.',
                'action' => null,
                'no_data_reason' => 'Guided Setup has no table of its own; it computes step completion live from other pages\' data, so there is nothing separate to read.',
            ],
            '/documents' => [
                'sources' => ['documents.library'],
                'purpose' => 'Stores, searches and organises the organisation\'s documents in folders.',
                'action' => null,
                'module' => self::MODULE,
                'title' => 'Document Library',
            ],
        ];
    }

    /** Document rows the caller may open, with names resolved. Mirrors the Document Library's own access rule. */
    private function visibleDocuments(AiRequestScope $scope): Builder
    {
        $q = DB::table('document_library');

        DocumentAccess::visibleTo($q, $scope->userId, $scope->selectedInstituteId, $this->callerDepartment($scope));

        return $q->whereNull('deleted_at')
            ->select(['id as document_id', 'title', 'document_type', 'category', 'document_date', 'size', 'visibility', 'processing_status'])
            ->selectRaw('(select d.department from hrms_departments d where d.id = document_library.department_id) as department')
            ->selectRaw('(select f.name from document_folders f where f.id = document_library.folder_id) as folder')
            ->orderByDesc('created_at');
    }

    private function callerDepartment(AiRequestScope $scope): ?int
    {
        $d = DB::table('tbluser')->where('id', $scope->userId)->value('department_id');

        return $d ? (int) $d : null;
    }
}

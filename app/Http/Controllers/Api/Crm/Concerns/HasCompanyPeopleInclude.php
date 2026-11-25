<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use App\Domain\Crm\Contacts\CompanyPeople;
use Illuminate\Http\Request;

/**
 * Switches `include=people` on for a host controller (S18, contract section 2): only CrmOrganizationController uses it. The shared concern traits that list a record's e-mails, documents,
 * comments and history (HasCrmEmailLinks, HasCrmDocuments, HasCrmComments, HasCrmAuditTrail) ask `companyPeopleFor()` when the host has it; a host that does not `use` this trait is asked
 * nothing and answers exactly as before.
 */
trait HasCompanyPeopleInclude
{
    /**
     * The live people of the company ($organizationId, the id in the URL) as contact id => name when the request says `include=people`; null otherwise (the default: the company's own rows only).
     * One query, tenant scoped; the caller has already checked that the company is a live row of this tenant.
     *
     * @return array<int, string>|null
     */
    protected function companyPeopleFor(Request $request, int $tenantId, int $organizationId): ?array
    {
        return CompanyPeople::wanted($request) ? CompanyPeople::of($tenantId, $organizationId) : null;
    }
}

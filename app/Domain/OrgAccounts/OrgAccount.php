<?php

namespace App\Domain\OrgAccounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One real-world organisation, pointing at whichever of its three source rows exist (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md).
 * A thin index: the CRM organisation, the GTM account and the Signals company stay exactly where they are.
 *
 * @property int $id
 * @property int $sub_institute_id
 * @property string $name
 * @property string $normalized_name
 * @property string|null $domain
 * @property string $lifecycle
 * @property int|null $crm_organization_id
 * @property int|null $gtm_account_id
 * @property int|null $g2g_company_id
 */
class OrgAccount extends Model
{
    use SoftDeletes;
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    public const SOURCE_CRM = 'crm_organization';

    public const SOURCE_GTM = 'gtm_account';

    public const SOURCE_COMPANY = 'g2g_company';

    public const SOURCES = [self::SOURCE_CRM, self::SOURCE_GTM, self::SOURCE_COMPANY];

    /** @var array<string, string> source type => the identity's pointer column */
    public const POINTERS = [self::SOURCE_CRM => 'crm_organization_id', self::SOURCE_GTM => 'gtm_account_id', self::SOURCE_COMPANY => 'g2g_company_id'];

    /** @var array<string, string> source type => the column that says why the pointer is there */
    public const BASES = [self::SOURCE_CRM => 'crm_link_basis', self::SOURCE_GTM => 'gtm_link_basis', self::SOURCE_COMPANY => 'company_link_basis'];

    public const LIFECYCLES = ['discovered', 'target', 'engaged', 'customer', 'archived'];

    public const BASIS_EXISTING = 'existing_link';

    public const BASIS_EXACT_NAME = 'exact_name';

    public const BASIS_EXACT_DOMAIN = 'exact_domain';

    public const BASIS_MANUAL = 'manual';

    protected $table = 'g2g_org_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'crm_organization_id' => 'integer',
        'gtm_account_id' => 'integer',
        'g2g_company_id' => 'integer',
        'owner_user_id' => 'integer',
    ];
}

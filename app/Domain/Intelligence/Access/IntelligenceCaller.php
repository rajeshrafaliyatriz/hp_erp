<?php

namespace App\Domain\Intelligence\Access;

use App\Support\RoleKey;

/**
 * WHO IS ASKING, resolved on the server and fail-closed.
 *
 * The organisation, the person, the profile and the department all come from the token owner's own `tbluser` row, NEVER from the request, a header, or "the
 * first organisation". A caller without an organisation (`sub_institute_id` NULL or 0) is refused: this class cannot be constructed for one. That is the
 * deliberate difference from the older `ResolvesApiIdentity` trait, whose fallback lets a tenant-less account name a tenant (audit finding, verified).
 *
 * DATA SCOPE (decision D-7 default: direct reports only, no hierarchy roll-up):
 *   employee           -> self
 *   reporting_manager  -> team        (people whose reporting_manager_id is the caller)
 *   department_head    -> department  (the caller's own department_id)
 *   hr_executive       -> department
 *   hr_manager, administrator, executive, auditor, recruiter -> organization
 *   no resolvable role -> self        (least privilege: an unresolved role is an employee)
 * Platform-owner cross-tenant access is NOT offered here: it needs a separate authorisation under the existing platform policy.
 */
final class IntelligenceCaller
{
    /** @var array<string, string> */
    private const SCOPE_BY_ROLE = [
        'employee' => 'self',
        'reporting_manager' => 'team',
        'department_head' => 'department',
        'hr_executive' => 'department',
        'hr_manager' => 'organization',
        'administrator' => 'organization',
        'executive' => 'organization',
        'auditor' => 'organization',
        'recruiter' => 'organization',
    ];

    /** Roles that see the organisation as aggregates only: no per-person references. */
    private const AGGREGATE_ONLY = ['executive', 'auditor'];

    public readonly ?string $roleKey;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $profileId,
        ?string $roleKey,
        public readonly ?int $departmentId,
        private readonly RightsChecker $rights,
    ) {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new IntelligenceDenied('No organisation.');
        }
        $this->roleKey = $roleKey !== null && isset(self::SCOPE_BY_ROLE[$roleKey]) ? $roleKey : null;
    }

    /**
     * Build from the token owner's user row. `$user` is the Sanctum tokenable; `$roleKey` is RoleKey::forProfileId(...) (null when unresolved).
     *
     * @throws IntelligenceDenied when the user has no organisation of their own
     */
    public static function fromUser(object $user, ?string $roleKey, RightsChecker $rights): self
    {
        return new self(
            (int) ($user->sub_institute_id ?? 0),
            (int) ($user->id ?? 0),
            (int) ($user->user_profile_id ?? 0),
            $roleKey,
            isset($user->department_id) && (int) $user->department_id > 0 ? (int) $user->department_id : null,
            $rights,
        );
    }

    /** self | team | department | organization. */
    public function scope(): string
    {
        return $this->roleKey === null ? 'self' : self::SCOPE_BY_ROLE[$this->roleKey];
    }

    public function hasRole(string ...$roleKeys): bool
    {
        return $this->roleKey !== null && in_array($this->roleKey, $roleKeys, true);
    }

    /** HR and administrators: the people the product already lets read the whole organisation's people data. */
    public function isHrOrAdmin(): bool
    {
        return $this->hasRole('hr_manager', 'hr_executive', 'administrator');
    }

    public function aggregateOnly(): bool
    {
        return $this->roleKey !== null && in_array($this->roleKey, self::AGGREGATE_ONLY, true);
    }

    /** Whether an insight may carry entity references (record id + kind) to individual people. */
    public function mayReferenceIndividuals(): bool
    {
        return ! $this->aggregateOnly();
    }

    /** Whether the caller's profile holds `$action` on ANY of the given menu rows (by access_link). Never true for an empty list. */
    public function canAny(array $accessLinks, string $action = 'view'): bool
    {
        foreach ($accessLinks as $link) {
            if ($this->rights->can($this->profileId, $this->tenantId, (string) $link, $action)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve the role key the way the rest of the product does (profile -> role_key, with the legacy-name fallback). */
    public static function roleKeyForProfile(int $profileId): ?string
    {
        return RoleKey::forProfileId($profileId);
    }
}

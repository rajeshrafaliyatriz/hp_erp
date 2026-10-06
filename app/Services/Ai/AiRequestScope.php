<?php

namespace App\Services\Ai;

/**
 * Who is asking, and which organisation they are asking about.
 *
 * The G2G counterpart of LMS K-12's `App\Services\Mcp\McpRequestContext`, with the
 * same contract and deliberately the same property names: every AI controller
 * reads `selectedInstituteId` off this object and never off request input, so a
 * caller cannot reach another organisation's rows by editing a query string.
 *
 * `allowedInstituteIds` exists for the same reason it does there — a scope filter
 * that pins to the selection and intersects with the allowed set must not be able
 * to contradict itself. In G2G a user belongs to exactly one organisation
 * (`tbluser.sub_institute_id`), so the set is normally one element; it is a set
 * rather than a scalar so a platform owner acting across tenants has somewhere to
 * be expressed without changing every reader.
 */
final class AiRequestScope
{
    /**
     * @param  array<int, int>  $allowedInstituteIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly int $selectedInstituteId,
        public readonly array $allowedInstituteIds,
        public readonly ?int $userProfileId,
        public readonly ?int $clientId,
        public readonly bool $isAdmin,
        public readonly bool $isPlatformOwner,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'role' => $this->role,
            'selected_institute_id' => $this->selectedInstituteId,
            'allowed_institute_ids' => $this->allowedInstituteIds,
            'user_profile_id' => $this->userProfileId,
            'client_id' => $this->clientId,
            'is_admin' => $this->isAdmin,
            'is_platform_owner' => $this->isPlatformOwner,
        ];
    }
}

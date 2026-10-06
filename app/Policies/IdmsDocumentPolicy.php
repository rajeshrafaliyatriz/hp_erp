<?php

namespace App\Policies;

use App\Models\Idms\DocumentMaster;

/**
 * Edit / download / share / delete decisions for an IDMS document. "May this
 * user SEE it" is the visibleTo scope; this class decides what they may DO
 * with what they can see. Admin means tbluser.is_admin 1 or 2 only: a profile
 * NAME is a per-tenant string anyone with settings access can edit.
 */
class IdmsDocumentPolicy
{
    protected function isAdmin($user): bool
    {
        return $user && in_array((int) ($user->is_admin ?? 0), [1, 2], true);
    }

    protected function isOwner($user, DocumentMaster $document): bool
    {
        $id = (int) ($user->id ?? 0);

        return $id > 0 && ((int) $document->owner_id === $id || (int) $document->created_by === $id);
    }

    public function view($user, DocumentMaster $document): bool
    {
        if (!$user || (int) $document->sub_institute_id !== (int) ($user->sub_institute_id ?? 0)) {
            return false;
        }
        if ($this->isAdmin($user) || $this->isOwner($user, $document) || $document->visibility === 'organization') {
            return true;
        }

        $deptId = $user->department_id ?? null;
        if ($document->visibility === 'department' && $deptId && (int) $document->department_id === (int) $deptId) {
            return true;
        }

        return (bool) array_intersect($this->principals($user), $document->view_principals ?: []);
    }

    public function update($user, DocumentMaster $document): bool
    {
        return $this->view($user, $document)
            && ($this->isAdmin($user) || $this->isOwner($user, $document) || $this->hasPermission($user, $document, 'edit', false));
    }

    public function download($user, DocumentMaster $document): bool
    {
        return $this->view($user, $document)
            && ($this->isAdmin($user) || $this->isOwner($user, $document) || $this->hasPermission($user, $document, 'download', true));
    }

    public function share($user, DocumentMaster $document): bool
    {
        return $this->view($user, $document)
            && ($this->isAdmin($user) || $this->isOwner($user, $document) || $this->hasPermission($user, $document, 'share', false));
    }

    public function delete($user, DocumentMaster $document): bool
    {
        return $user
            && (int) $document->sub_institute_id === (int) ($user->sub_institute_id ?? 0)
            && ($this->isAdmin($user) || $this->isOwner($user, $document));
    }

    protected function principals($user): array
    {
        $principals = ['user:' . (int) ($user->id ?? 0)];
        if (!empty($user->user_profile_id)) {
            $principals[] = 'role:' . (int) $user->user_profile_id;
        }
        if (!empty($user->department_id)) {
            $principals[] = 'dept:' . (int) $user->department_id;
        }

        return $principals;
    }

    /** First matching entry in the permissions JSON wins; $default applies when nothing names this user. */
    protected function hasPermission($user, DocumentMaster $document, string $field, bool $default): bool
    {
        $permissions = $document->permissions;
        if (empty($permissions) || !is_array($permissions)) {
            return $default;
        }

        $userId = (int) ($user->id ?? 0);
        $profileId = (int) ($user->user_profile_id ?? 0);
        $deptId = (int) ($user->department_id ?? 0);

        foreach ($permissions as $perm) {
            $id = (int) ($perm['id'] ?? 0);
            $match = match ($perm['type'] ?? '') {
                'user' => $id === $userId,
                'role' => $profileId > 0 && $id === $profileId,
                'department' => $deptId > 0 && $id === $deptId,
                default => false,
            };

            if ($match && isset($perm[$field])) {
                return (bool) $perm[$field];
            }
        }

        return $default;
    }
}

<?php

namespace App\Models\Idms;

use App\Models\HrmsDepartment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** IDMS document: one row per document, current state only. */
class DocumentMaster extends Model
{
    use SoftDeletes;

    protected $table = 'document_master';

    protected $fillable = [
        'sub_institute_id', 'title', 'original_file_name', 'mime_type', 'size',
        'checksum_sha256', 'storage_path', 'preview_path', 'current_version',
        'document_type', 'category', 'department_id', 'subject', 'document_date',
        'academic_year', 'organization', 'project', 'lifecycle_status', 'summary',
        'confidence', 'people', 'keywords', 'extracted_text', 'tags_text', 'tags',
        'tag_names', 'embedding', 'owner_id', 'visibility', 'view_principals',
        'permissions', 'processing_status', 'processing_error', 'warnings', 'created_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'current_version' => 'integer',
        'confidence' => 'float',
        'document_date' => 'date:Y-m-d',
        'people' => 'array',
        'keywords' => 'array',
        'tags' => 'array',
        'tag_names' => 'array',
        'view_principals' => 'array',
        'permissions' => 'array',
        'warnings' => 'array',
    ];

    /**
     * Rows the given user may see, inside one tenant. Applied to EVERY list,
     * search, count, tree, tag-cloud, related and duplicate query.
     *
     * The tenant filter is not optional: a caller without a tenant sees nothing.
     */
    public function scopeVisibleTo(Builder $query, $user, ?int $subInstituteId = null): Builder
    {
        if (!$user || !$subInstituteId) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('document_master.sub_institute_id', $subInstituteId);

        $userId = (int) ($user->id ?? 0);
        if (in_array((int) ($user->is_admin ?? 0), [1, 2], true)) {
            return $query;
        }

        $profileId = (int) ($user->user_profile_id ?? 0);
        $deptId = ($user->department_id ?? null) ? (int) $user->department_id : null;

        $principals = ["user:{$userId}"];
        if ($profileId) {
            $principals[] = "role:{$profileId}";
        }
        if ($deptId) {
            $principals[] = "dept:{$deptId}";
        }
        $jsonPrincipals = json_encode($principals);

        return $query->where(function (Builder $q) use ($userId, $deptId, $jsonPrincipals) {
            $q->where('document_master.owner_id', $userId)
                ->orWhere('document_master.created_by', $userId)
                ->orWhere('document_master.visibility', 'organization');

            if ($deptId) {
                $q->orWhere(function (Builder $sub) use ($deptId) {
                    $sub->where('document_master.visibility', 'department')
                        ->where('document_master.department_id', $deptId);
                });
            }

            $q->orWhereRaw('JSON_OVERLAPS(document_master.view_principals, ?)', [$jsonPrincipals]);
        });
    }

    /** Recompute view_principals from visibility, department, owner and permission overrides. */
    public function recomputeViewPrincipals(): array
    {
        $principals = [];

        if ($this->owner_id) {
            $principals[] = "user:{$this->owner_id}";
        }
        if ($this->visibility === 'department' && $this->department_id) {
            $principals[] = "dept:{$this->department_id}";
        }
        if (is_array($this->permissions)) {
            foreach ($this->permissions as $perm) {
                if (empty($perm['view'])) {
                    continue;
                }
                $targetId = (int) ($perm['id'] ?? 0);
                $principals[] = match ($perm['type'] ?? '') {
                    'user' => "user:{$targetId}",
                    'role' => "role:{$targetId}",
                    'department' => "dept:{$targetId}",
                    default => null,
                };
            }
        }

        $unique = array_values(array_unique(array_filter($principals)));
        $this->view_principals = $unique;

        return $unique;
    }

    /** Keep tags, tag_names and tags_text in step. Call inside the caller's transaction. */
    public function syncTags(array $tags): void
    {
        $clean = [];
        $accepted = [];

        foreach ($tags as $tag) {
            $name = trim((string) ($tag['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $status = in_array($tag['status'] ?? '', ['accepted', 'suggested', 'rejected'], true) ? $tag['status'] : 'suggested';
            $source = ($tag['source'] ?? '') === 'user' ? 'user' : 'ai';
            $clean[] = ['name' => $name, 'source' => $source, 'status' => $status];
            if ($status === 'accepted') {
                $accepted[] = mb_strtolower($name);
            }
        }

        $accepted = array_values(array_unique($accepted));
        $this->tags = $clean;
        $this->tag_names = $accepted;
        $this->tags_text = implode(' ', $accepted);
    }

    public function department()
    {
        return $this->belongsTo(HrmsDepartment::class, 'department_id');
    }

    public function history()
    {
        return $this->hasMany(DocumentHistory::class, 'document_id')->orderBy('created_at', 'desc');
    }
}

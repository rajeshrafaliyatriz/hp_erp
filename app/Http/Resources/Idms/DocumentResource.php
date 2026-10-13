<?php

namespace App\Http\Resources\Idms;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class DocumentResource extends JsonResource
{
    /** @var array<int, ?string> owner id => name, shared across the rows of one response */
    private static array $ownerNames = [];

    private function ownerName(): ?string
    {
        $id = (int) $this->owner_id;
        if (!array_key_exists($id, self::$ownerNames)) {
            $row = DB::table('tbluser')->where('id', $id)->first(['first_name', 'last_name']);
            self::$ownerNames[$id] = $row ? trim("{$row->first_name} {$row->last_name}") : null;
        }

        return self::$ownerNames[$id];
    }

    public function toArray($request)
    {
        $dept = $this->department ? $this->department->department : null;
        $location = [
            'root' => 'Documents',
            'department' => $dept ?: 'General',
            'document_type' => $this->document_type ?: 'Unclassified',
            'academic_year' => $this->academic_year ?: 'General',
            'subject' => $this->subject ?: 'General',
        ];
        $location['path'] = implode(' > ', $location);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_file_name' => $this->original_file_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'current_version' => $this->current_version,
            'document_type' => $this->document_type,
            'category' => $this->category,
            'department_id' => $this->department_id,
            'department_name' => $dept,
            'subject' => $this->subject,
            'document_date' => $this->document_date ? $this->document_date->format('Y-m-d') : null,
            'academic_year' => $this->academic_year,
            'organization' => $this->organization,
            'project' => $this->project,
            'lifecycle_status' => $this->lifecycle_status,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'people' => $this->people ?: [],
            'keywords' => $this->keywords ?: [],
            'tags' => $this->tags ?: [],
            'tag_names' => $this->tag_names ?: [],
            'owner_id' => $this->owner_id,
            'owner_name' => $this->ownerName(),
            'visibility' => $this->visibility,
            'permissions' => $this->permissions ?: [],
            'processing_status' => $this->processing_status,
            'processing_error' => $this->processing_error,
            'warnings' => $this->warnings ?: [],
            'logical_location' => $location,
            'snippet' => $this->when(isset($this->snippet), $this->snippet),
            'deleted_at' => $this->deleted_at ? $this->deleted_at->toIso8601String() : null,
            'purge_at' => $this->deleted_at
                ? $this->deleted_at->copy()->addDays((int) config('idms.trash_retention_days', 30))->toIso8601String()
                : null,
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toIso8601String() : null,
        ];
    }
}

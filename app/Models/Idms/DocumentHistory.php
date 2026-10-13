<?php

namespace App\Models\Idms;

use Illuminate\Database\Eloquent\Model;

/** One document_history row: a version (entry_type=version) or an audit entry. */
class DocumentHistory extends Model
{
    protected $table = 'document_history';

    public $timestamps = false;

    protected $fillable = [
        'document_id', 'entry_type', 'action', 'user_id', 'ip_address',
        'version_number', 'storage_path', 'checksum_sha256', 'size', 'change_note',
        'details', 'created_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'size' => 'integer',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentMaster::class, 'document_id')->withTrashed();
    }
}

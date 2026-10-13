<?php

namespace App\Domain\Gtm;

use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped (sub_institute_id). Never query this without the caller's tenant. */
class GtmOutreachMessage extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'gtm_outreach_messages';

    protected $guarded = ['id'];

    protected $casts = ['due_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'sent_at' => 'datetime'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'sending', 'sent', 'failed', 'cancelled'];

    /** The exact text that is approved and later sent. */
    public function contentHash(): string
    {
        return hash('sha256', $this->subject."\n".$this->body);
    }
}

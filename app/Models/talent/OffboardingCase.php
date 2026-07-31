<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One departing employee's exit, from notice to closure.
 *
 * Backs the dashboard's Offboarding KPI card and the Offboarding Center's exit
 * register.
 *
 * The exit interview lives on this row (exit_interview_done / _date / _notes)
 * rather than in its own table - that is how talent_offboarding_cases is
 * already shaped, and adding a parallel table would give the same fact two
 * homes.
 */
class OffboardingCase extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_offboarding_cases';
    protected $guarded = ['id'];

    public const EXIT_TYPES = ['resignation', 'termination', 'retirement', 'end-of-contract', 'absconding'];

    /**
     * The exit workflow. Kept within varchar(20) - the column's real width -
     * so no value is silently truncated on write.
     */
    public const STATUSES = [
        'initiated', 'notice-period', 'clearance',
        'exit-interview', 'awaiting-fnf', 'closed', 'cancelled',
    ];

    /** Still in flight - what the Offboarding KPI counts. */
    public const ACTIVE_STATUSES = [
        'initiated', 'notice-period', 'clearance', 'exit-interview', 'awaiting-fnf',
    ];

    protected $casts = [
        'sub_institute_id'    => 'integer',
        'employee_id'         => 'integer',
        'department_id'       => 'integer',
        'manager_id'          => 'integer',
        'notice_date'         => 'date',
        'last_working_day'    => 'date',
        'exit_interview_done' => 'boolean',
        'exit_interview_date' => 'date',
        'created_by'          => 'integer',
        'updated_by'          => 'integer',
    ];

    public function clearances()
    {
        return $this->hasMany(OffboardingClearance::class, 'case_id');
    }
}

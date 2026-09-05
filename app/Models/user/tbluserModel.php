<?php

namespace App\Models\user;

use Illuminate\Database\Eloquent\Model;
use App\Models\auth\schoolSetupModel;
use App\Models\auth\tblclientModel;
use App\Models\auth\academicSectionModel;
use App\Models\auth\tbluserprofileMasterModel;

class tbluserModel extends Model
{
    public $timestamps = false;

    protected $table = "tbluser";
    protected $appends = ['full_name'];

    /**
     * Never serialised. F-92, HRIT Sprint 1.
     *
     * employeeDetails() (app/Helpers/helpers.php) selects `tbluser.*` and its
     * result is returned verbatim by GET /employee-salary-structure and
     * GET /payroll-deduction, so every caller of those endpoints received every
     * employee's bcrypt hash - 122 of them per request on tenant 3 - plus the
     * live login OTP. Hiding them here fixes every consumer of the model at
     * once, which an explicit column list in one helper would not.
     *
     * $hidden affects array/JSON output only. authController still compares
     * Hash::check($password, $user->password) as an attribute, and the mobile
     * login still returns `otp` because it reads through DB::table(), not this
     * model. Both verified before this was added.
     */
    protected $hidden = ['password', 'remember_token', 'otp'];

    protected $fillable = [
        'id',
        'user_name',
        'password',
        'name_suffix',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'mobile',
        'gender',
        'birthdate',
        'address',
        'city',
        'state',
        'pincode',
        'otp',
        'user_profile_id',
        'join_year',
        'image',
        'sub_institute_id',
        'client_id',
        'is_admin',
        'status',
        'last_login',
        'landmark',
        'address_2',
        'created_on',
        'expire_date',
        'total_lecture',
        'subject_ids',
        'jobtitle_id',
        'department_id',
        'jobtitle_id',
        'department_id',
        'joined_date',
        'probation_period_from',
        'probation_period_to',
        'terminated_date',
        'termination_reason',
        'notice_fromdate',
        'notice_todate',
        'noticereason',
        'openingleave',
        'relieving_date',
        'relieving_reason',
        'CL_opening_leave',
        'supervisor_opt',
        'employee_id',
        'reporting_method',
        'branch_name',
        'amount',
        'transfer_type',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
        'monday_in_date',
        'monday_out_date',
        'tuesday_in_date',
        'tuesday_out_date',
        'wednesday_in_date',
        'wednesday_out_date',
        'thursday_in_date',
        'thursday_out_date',
        'friday_in_date',
        'friday_out_date',
        'saturday_in_date',
        'saturday_out_date',
        'sunday_in_date',
        'sunday_out_date',
        'bank_name',
        'account_no',
        'ifsc_code',
        'fcm_token'
    ];

    /**
     * The employee's display name.
     *
     * FILTERED, not concatenated. The old version glued all three parts with
     * spaces unconditionally, so the 95% of rows with no middle name rendered
     * as "Milan  Baldaniya" - a double space visible in the Employee
     * Directory, the profile header and every picker that shows a name.
     *
     * This is an APPENDED ATTRIBUTE, never a column. tbluser has no full_name
     * and a query that selects one is rejected outright - which is exactly how
     * /user/add_user came to answer 500 for every API caller.
     */
    public function getFullNameAttribute()
    {
        // (string) before trim: these columns are nullable, and PHP 8.2
        // deprecates passing null to trim().
        $parts = array_filter(
            array_map(fn ($part) => trim((string) $part), [$this->first_name, $this->middle_name, $this->last_name]),
            fn ($part) => $part !== '',
        );

        return implode(' ', $parts);
    }

    public function organization()
    {
        return $this->belongsTo(schoolSetupModel::class, 'sub_institute_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(tblclientModel::class, 'client_id', 'id');
    }

    public function yearData()
    {
        return $this->belongsTo(academicSectionModel::class, 'sub_institute_id', 'sub_institute_id');
    }
     public function userProfile()
    {
        return $this->belongsTo(tbluserprofileMasterModel::class, 'user_profile_id', 'id');
    }
}

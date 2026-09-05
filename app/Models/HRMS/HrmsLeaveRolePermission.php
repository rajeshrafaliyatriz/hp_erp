<?php

namespace App\Models\HRMS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrmsLeaveRolePermission extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'hrms_leave_role_permissions';
    protected $guarded = ['id'];

    protected $casts = [
        'approve_leave'      => 'boolean',
        'view_reports'       => 'boolean',
        'configure_settings' => 'boolean',
        'bulk_operations'    => 'boolean',
        'escalation_rights'  => 'boolean',
        'user_management'    => 'boolean',
        'status'             => 'boolean',
        'sort_order'         => 'integer',
    ];

    public const PERMISSION_KEYS = [
        'approve_leave',
        'view_reports',
        'configure_settings',
        'bulk_operations',
        'escalation_rights',
        'user_management',
    ];

    /**
     * Seeded on first read so an institute that has never configured roles still
     * gets the standard leave hierarchy instead of an empty table.
     */
    public static function defaults(): array
    {
        return [
            ['role_name' => 'Employee',          'scope' => 'Self',         'approve_leave' => false, 'view_reports' => true, 'configure_settings' => false, 'bulk_operations' => false, 'escalation_rights' => false, 'user_management' => false, 'sort_order' => 1],
            ['role_name' => 'Reporting Manager', 'scope' => 'Team',         'approve_leave' => true,  'view_reports' => true, 'configure_settings' => false, 'bulk_operations' => false, 'escalation_rights' => false, 'user_management' => false, 'sort_order' => 2],
            ['role_name' => 'Department Head',   'scope' => 'Department',   'approve_leave' => true,  'view_reports' => true, 'configure_settings' => true,  'bulk_operations' => false, 'escalation_rights' => false, 'user_management' => false, 'sort_order' => 3],
            ['role_name' => 'HR Executive',      'scope' => 'Department',   'approve_leave' => true,  'view_reports' => true, 'configure_settings' => true,  'bulk_operations' => false, 'escalation_rights' => false, 'user_management' => false, 'sort_order' => 4],
            ['role_name' => 'HR Manager',        'scope' => 'Organization', 'approve_leave' => true,  'view_reports' => true, 'configure_settings' => true,  'bulk_operations' => true,  'escalation_rights' => true,  'user_management' => true,  'sort_order' => 5],
            ['role_name' => 'Administrator',     'scope' => 'Organization', 'approve_leave' => true,  'view_reports' => true, 'configure_settings' => true,  'bulk_operations' => true,  'escalation_rights' => true,  'user_management' => true,  'sort_order' => 6],
            ['role_name' => 'Executive',         'scope' => 'Organization', 'approve_leave' => true,  'view_reports' => true, 'configure_settings' => true,  'bulk_operations' => true,  'escalation_rights' => true,  'user_management' => false, 'sort_order' => 7],
            // Added in HRIT Sprint 1. The platform defines nine role_keys and
            // this matrix covered seven, so auditor and recruiter resolved to no
            // row at all - which now means "deny everything" and would have
            // silently locked two real roles out of their own leave.
            //
            // Auditor reads the organisation and changes nothing: that is what
            // the role is for, and it is the only row here with view_reports
            // true and approve_leave false at Organization scope.
            //
            // Recruiter gets the Employee row. For leave purposes a recruiter is
            // an employee; their elevated rights are in Talent, not here.
            ['role_name' => 'Auditor',           'scope' => 'Organization', 'approve_leave' => false, 'view_reports' => true, 'configure_settings' => false, 'bulk_operations' => false, 'escalation_rights' => false, 'user_management' => false, 'sort_order' => 8],
            ['role_name' => 'Recruiter',         'scope' => 'Self',         'approve_leave' => false, 'view_reports' => true, 'configure_settings' => false, 'bulk_operations' => false, 'escalation_rights' => false, 'user_management' => false, 'sort_order' => 9],
        ];
    }
}

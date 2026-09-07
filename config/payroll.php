<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flat pay head exceeding its configured amount
    |--------------------------------------------------------------------------
    |
    | When a pay head is Flat (amount_type = 1) and payroll_types.payroll_percentage
    | holds a non-zero amount, and the figure entered for an employee is HIGHER
    | than it, two different things can be meant:
    |
    |   listed here      pay the EXCESS:  amount = entered - configured
    |   everyone else    CLAMP to it:     amount = configured
    |
    | This was `$sub_institute_id == 47`, written inline in the middle of
    | PayrollController::employeeSalaryStructureStore() (F-111). The `elseif`
    | beside it still carries the comment "added for another institutes on
    | 14-05-2025", so tenant 47 was the original behaviour and the general case
    | was added next to it later.
    |
    | The id is here rather than in the expression so the rule is visible and
    | settable. THE ARITHMETIC IS UNCHANGED - this is a move, not a fix. Tenant
    | 47 is a live institute with 597 users and 924 salary structures on the
    | lms.triz.co.in deployment, so both branches are somebody's payslip.
    |
    | Which of the two behaviours *should* be the default is a domain question
    | and is open as Q1 in Docs/hrit-audit/AUDIT-HRIT-MANAGEMENT.md. Do not
    | unify the branches until it is answered.
    |
    */

    'excess_over_flat_amount_tenants' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('PAYROLL_EXCESS_OVER_FLAT_TENANTS', '47'))
    ))),

];

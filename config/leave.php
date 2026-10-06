<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SLA auto-decisions
    |--------------------------------------------------------------------------
    |
    | Whether a leave approval step whose SLA has passed may be APPROVED OR
    | REJECTED by the platform itself, with nobody having read it.
    |
    | OFF BY DEFAULT, AND THE DEFAULT IS THE POINT.
    |
    | Everything else about leave escalation widens who may decide and never
    | decides. `auto_approve` and `auto_reject` are the first mechanism in this
    | product that settles somebody's leave request unread, so switching them on
    | is a deliberate act by a named person, not a side effect of deploying.
    |
    | The concrete risk this guards: a tenant saves a chain in the platform
    | console to see what the screen does, leaves `auto_approve` on a step
    | because it was in the dropdown, and a week later leave is being granted
    | that nobody checked. With this off, that chain escalates instead and the
    | command reports what it would have done.
    |
    | When it is on, every auto-decision is attributed to SYSTEM with the rule
    | and the SLA recorded on the step, and a step that demands a comment is
    | refused outright — see LeaveApprovalWorkflow::autoDecide().
    |
    */
    'auto_decisions_enabled' => env('G2G_LEAVE_AUTO_DECIDE', false),

];

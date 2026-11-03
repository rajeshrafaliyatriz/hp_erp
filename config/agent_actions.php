<?php

/*
|--------------------------------------------------------------------------
| Agent gateway (agentic CRM, slice 7 thin: the Organisation agent)
|--------------------------------------------------------------------------
|
| Docs/agentic-crm/slices/S07-agent-gateway-thin.md. Everything an operator or an administrator may want to change without touching code.
|
| AUTONOMY. `actions` is the DEFAULT level of each action a model may ask for. An administrator overrides a level per organisation in the
| g2g_agent_autonomy table (action_key => level); a row with action_key '*' and level 'off' is that organisation's kill switch (a row for
| organisation 0 switches the agent off for everybody). Levels:
|
|   off       the action is refused; nothing is stored
|   suggest   shown to a person, who accepts or dismisses it   } in this first slice all three behave the same:
|   draft     shown to a person, who accepts or dismisses it   } a pending proposal. They are kept apart so later slices
|   approval  shown to a person, who accepts or dismisses it   } (drafts for outreach, say) can tell them apart.
|   alone     applied at once, marked "Agent", and recorded
|
| The owner's standing default (2026-11-02): agents act alone only for notes and tasks; everything else waits for a person. An action that is not in
| this list is not an action: there is no tool for it, and no level can create one.
*/

return [
    'actions' => [
        'add_note' => 'alone',
        'add_task' => 'alone',
        'confirm_link' => 'approval',
    ],

    /** The levels an administrator may set. */
    'levels' => ['off', 'suggest', 'draft', 'approval', 'alone'],

    /**
     * The AI module the agent calls the model under. 'gtm' is the CRM's own module (GTM & Revenue: account research, outreach drafting, deal
     * coaching), so an organisation's choice of model for its revenue work applies here and the call is priced from the same rate card.
     */
    'ai_module' => env('AGENT_AI_MODULE', 'gtm'),

    /** Tokens the model may write in one run, and the temperature it writes at (low: the output is a structured brief and a few proposals). */
    'max_output_tokens' => (int) env('AGENT_MAX_OUTPUT_TOKENS', 1200),
    'temperature' => 0.2,
    'timeout_seconds' => 90,

    /** How many characters of facts one run may send to the model, and how long its answers may be. */
    'max_context_chars' => 14000,
    'max_proposals' => 5,
    'max_brief_chars' => 600,
    'max_note_chars' => 1000,
    'max_task_title_chars' => 150,
    'max_task_description_chars' => 500,
    'max_reason_chars' => 300,
    'max_evidence_refs' => 8,

    /** A task's due date must fall between today and this many days from now. */
    'due_window_days' => 90,

    /** Runs one organisation's identity may have started per calendar day (in the application's timezone). */
    'daily_run_cap' => (int) env('AGENT_DAILY_RUN_CAP', 10),

    /** A run still 'running' after this many minutes is taken to have died; a new one may start. */
    'stale_run_minutes' => 5,

    /** How many pending proposals one organisation may hold; the agent adds no more until a person has worked through them. */
    'max_pending_per_subject' => 10,
];

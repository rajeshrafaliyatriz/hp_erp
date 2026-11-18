<?php

/*
|--------------------------------------------------------------------------
| Ask (agentic CRM, slice 8: the chat)
|--------------------------------------------------------------------------
|
| Docs/agentic-crm/slices/S08-ask.md. A question is answered in two bounded model calls: a planner that picks up to three read-only lookups, and an
| answerer that writes from ONLY what the lookups returned. The model is the one the Organisation agent uses (config/agent_actions.php `ai_module`), and
| every call is priced from the same rate card and filed under the question's run (g2g_agent_runs, agent_key `ask`).
|
| Nothing here can add a tool, a write or a send: the five tools are fixed in code (App\Domain\Ask\AskToolRegistry). These are only limits.
*/

return [
    /** Questions one person may ask per calendar day (the application's timezone). A question the model could not be asked for (no model set up) does not count. */
    'daily_question_cap' => (int) env('ASK_DAILY_QUESTION_CAP', 30),

    /** The longest question, in characters. */
    'max_message_chars' => 1000,

    /** How many lookups one question may run, however many the planner asks for; the rest are dropped and audited. */
    'max_tool_calls' => 3,

    /** Rows one lookup may return (a search, the signals list), and the related rows listed under a record that is opened. */
    'max_search_rows' => 10,
    'max_signal_rows' => 10,
    'max_product_rows' => 40,
    'max_related_rows' => 5,
    'max_notes' => 3,

    /** How many characters of retrieved rows the answerer is shown. A row that does not fit is not shown and so cannot be cited. */
    'max_result_chars' => 12000,

    /** How much free text of a record (description, a note, a next step) the answerer may be shown. */
    'max_text_chars' => 300,

    /** The model's token caps per call (a request: an administrator's saved per-key ceiling still wins, see AiModelClient). */
    'planner_max_tokens' => 400,
    'answer_max_tokens' => 800,

    /** Earlier messages of the conversation the planner may read to resolve "and for them?" (data, not instructions). */
    'history_messages' => 4,
    'history_message_chars' => 300,

    /** The answer: how many statements, how long each is, how many records one statement may cite, and the whole text. */
    'max_statements' => 8,
    'max_statement_chars' => 400,
    'max_citations_per_statement' => 5,
    'max_answer_chars' => 1500,

    /** The conversation list and one conversation. */
    'conversations_per_page' => 20,
    'max_messages_read' => 100,
    'title_chars' => 150,
];

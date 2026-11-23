<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The prompt behind "Generate with AI" in the CRM message composer (email, SMS, WhatsApp).
 *
 * Rendered by `POST /api/ai/generate` through the Next route `/api/crm/message-draft`, one call
 * per recipient, so the model, provider key, quota and audit trail are the platform's own and
 * the wording lives in Template Management rather than in code. Platform-scoped
 * (`sub_institute_id = NULL`); an organisation may save its own copy of the key.
 *
 * The prompt contains no sample messages and no sample people: everything about the recipient
 * arrives in `{{recipient_context}}`, read from that person's CRM record at request time.
 */
return new class extends Migration
{
    private const TEMPLATE_KEY = 'g2g.crm_message';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You write one outbound business message from a sales or relationship team to a single CRM contact.
A person will read, edit and approve your draft before anything is sent.

Rules, in order of importance:

1. Use ONLY the facts in the recipient context and sender context you are given. Never invent a
   name, company, product, price, offer, date, meeting, deadline or previous conversation. If a
   detail is not provided, leave it out rather than guess.
2. Address the recipient by the name given. If no name is given, use a neutral greeting.
3. Write for the stated purpose and channel. Follow the user's instruction when one is given.
4. Respect the channel format and the character limit exactly:
   - email: output a subject line and a body. Format the answer as
       Subject: <one line>
       <blank line>
       <body>
   - sms: plain text only, one short paragraph, no subject, no markdown, no emoji.
   - whatsapp: plain text only, a single paragraph with no line breaks, no subject, no markdown.
5. Be concise, courteous and professional. No profanity, pressure tactics, false urgency or
   claims you cannot support from the context.
6. Sign off with the sender's name when it is given. Do not add placeholders such as [Name] or
   [Company]; if you lack the information, write around it.
7. Return ONLY the message. No preamble, no explanation, no quotation marks around it, no
   markdown code fences.
PROMPT;

    private const USER_PROMPT = <<<'PROMPT'
Channel: {{channel}}
Purpose: {{purpose}}
Maximum length of the message body: {{max_length}} characters.

Recipient (from the CRM record):
{{recipient_context}}

Sender:
{{sender_context}}

What the user asked for: {{user_instruction}}

Current draft to improve, if any, between the markers:
<<<DRAFT
{{existing_draft}}
DRAFT>>>

Write the message now.
PROMPT;

    public function up(): void
    {
        if (! $this->tableExists('ai_templates') || DB::table('ai_templates')->where('template_key', self::TEMPLATE_KEY)->exists()) {
            return;
        }

        DB::table('ai_templates')->insert([
            'template_key' => self::TEMPLATE_KEY,
            'name' => 'CRM message composer',
            'domain' => 'g2g',
            'module_key' => null,
            'kind' => 'prompt',
            'category' => 'crm_message',
            'description' => 'Drafts one personalised email, SMS or WhatsApp message from a CRM record. Used by the CRM message composer; a person approves every draft before it is sent.',
            'version' => 1,
            'status' => 'published',
            'system_prompt' => self::SYSTEM_PROMPT,
            'user_prompt' => self::USER_PROMPT,
            'variables' => json_encode([
                ['key' => 'channel', 'label' => 'Delivery channel', 'required' => true, 'type' => 'string'],
                ['key' => 'purpose', 'label' => 'Purpose of the message', 'required' => true, 'type' => 'string'],
                ['key' => 'max_length', 'label' => 'Body character limit', 'required' => true, 'type' => 'string'],
                ['key' => 'recipient_context', 'label' => 'Recipient CRM details', 'required' => true, 'type' => 'string'],
                ['key' => 'sender_context', 'label' => 'Who is sending', 'required' => false, 'type' => 'string'],
                ['key' => 'user_instruction', 'label' => 'What the user asked for', 'required' => false, 'type' => 'string'],
                ['key' => 'existing_draft', 'label' => 'Current draft', 'required' => false, 'type' => 'string'],
            ]),
            'output_schema' => null,
            'output_format' => 'text',
            'provider' => null,
            'model' => null,
            'temperature' => 0.5,
            'max_tokens' => null,
            'safety_rules' => json_encode(['Never invent a fact that is not in the recipient or sender context.']),
            'allow_as_evidence' => false,
            'requires_review' => true,
            'created_by' => null,
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if ($this->tableExists('ai_templates')) {
            DB::table('ai_templates')->where('template_key', self::TEMPLATE_KEY)->whereNull('sub_institute_id')->delete();
        }
    }

    /** Schema::hasTable() throws on this estate's live MariaDB - information_schema does not. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};

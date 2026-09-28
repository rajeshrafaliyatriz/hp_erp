<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The prompt behind the "AI assist" sparkle on AI Stack form fields.
 *
 * G2G's copy of LMS_K12's `k12.field_edit` template: same variables, same rules, reworded
 * for an HR and talent platform. Rendered by `POST /api/ai/generate`, which the shared
 * field assistant calls, so the prompt lives in Template Management where it can be read
 * and improved rather than in code. Platform-scoped and shared across modules
 * (`module_key = NULL`); an organisation may save its own copy of the key.
 */
return new class extends Migration
{
    private const TEMPLATE_KEY = 'g2g.field_edit';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You edit text inside an HR, talent and learning management platform. An administrator has
asked you to change one field on a form.

Rules, in order of importance:

1. Return ONLY the replacement text for the field. No preamble, no sign-off, no explanation, no
   quotation marks around the whole answer, and no markdown code fences.
2. Never invent facts. Do not add or change a date, name, role, department, figure, score,
   policy reference or citation that is not already in the text or the context you were given.
   If the instruction cannot be followed without inventing something, do the part you can and
   leave the rest as it was.
3. Follow the user's instruction exactly. If they asked only to fix grammar, do not also reword,
   reorder or shorten.
4. Keep the original language unless you were explicitly asked to translate.
5. Keep the formatting shape you were given — if the input was HTML, return HTML; if it was plain
   text, return plain text; if it was a list, return a list.
6. Content is read by employees and candidates. No profanity, no stereotyping, no emoji, and
   nothing that singles out or demeans a person.
7. If the text is already correct for the instruction, return it unchanged rather than inventing a
   difference.
PROMPT;

    private const USER_PROMPT = <<<'PROMPT'
{{field_guidance}}

Where this field lives:
{{field_context}}
{{related_content}}
Current field content, between the markers:
<<<FIELD
{{field_value}}
FIELD>>>

What the user asked for: {{user_instruction}}

Reply with the replacement field content and nothing else.
PROMPT;

    public function up(): void
    {
        if (! Schema::hasTable('ai_templates') || DB::table('ai_templates')->where('template_key', self::TEMPLATE_KEY)->exists()) {
            return;
        }

        DB::table('ai_templates')->insert([
            'template_key' => self::TEMPLATE_KEY,
            'name' => 'Field edit assistant',
            'domain' => 'g2g',
            'module_key' => null,
            'kind' => 'prompt',
            'category' => 'field_edit',
            'description' => 'Rewrites one form field from the user\'s instruction. Used by the AI assist sparkle across every AI Stack.',
            'version' => 1,
            'status' => 'published',
            'system_prompt' => self::SYSTEM_PROMPT,
            'user_prompt' => self::USER_PROMPT,
            'variables' => json_encode([
                ['key' => 'field_value', 'label' => 'Current field content', 'required' => false, 'type' => 'string'],
                ['key' => 'user_instruction', 'label' => 'What the user asked for', 'required' => true, 'type' => 'string'],
                ['key' => 'field_guidance', 'label' => 'Field-type guidance', 'required' => false, 'type' => 'string'],
                ['key' => 'field_context', 'label' => 'Where the field lives', 'required' => false, 'type' => 'string'],
                ['key' => 'related_content', 'label' => 'Nearby content, for reference', 'required' => false, 'type' => 'string'],
            ]),
            'output_schema' => null,
            'output_format' => 'text',
            'provider' => null,
            'model' => null,
            'temperature' => 0.3,
            'max_tokens' => null,
            'safety_rules' => json_encode(['Never invent a fact that is not in the field or its context.']),
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
        if (Schema::hasTable('ai_templates')) {
            DB::table('ai_templates')->where('template_key', self::TEMPLATE_KEY)->whereNull('sub_institute_id')->delete();
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for Conversational AI and AI Evaluation.
 *
 * ── WHY CONVERSATIONS NEED A TABLE AT ALL ──────────────────────────────────
 *
 * They already "worked": `packages/conversational-ai-core/src/history.ts` keeps them
 * in a module-level `Map`. That is not storage. It is lost on every deploy and every
 * restart, it is per-process so two instances behind a load balancer disagree about
 * what was said, and it is unreachable from the server — so the AI & Intelligence
 * console could report a conversation count only by reading `hpbrain_conversation_*`,
 * tables a different application writes and this one does not control.
 *
 * Worse, an assistant answering questions about an organisation's people with no
 * durable record of what it was asked or what it replied is the one capability where
 * that is least acceptable. AI Audit exists to answer "what did the system do about
 * this person", and a conversation held in a `Map` cannot be part of that answer.
 *
 * Shaped like LMS K-12's `ai_conversations` / `ai_conversation_turns` so the two
 * products' transcripts mean the same thing.
 *
 * ── WHY EVALUATION NEEDS TWO TABLES AND NOT `hpbrain_ai_evaluations` ───────
 *
 * That table exists and is empty, and its shape is why: `dataset` and `results` are
 * both `longtext` blobs. A run's cases and their outcomes go in as JSON, which means
 * nothing can be queried — not "which case regressed", not "how did this template
 * score last month", not "show me the failures". An evaluation you cannot query is a
 * log file with a row id.
 *
 * So cases are rows. `ai_evaluation_cases` holds one per input, with its expectation,
 * what the model actually returned and what it scored, which is what makes a
 * before-and-after answerable — the entire point of the capability.
 *
 * SAFE TO RE-RUN. Every create is guarded.
 *
 *   php artisan migrate --path=database/migrations/2026_09_21_140000_create_ai_conversation_and_evaluation_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createConversations();
        $this->createConversationTurns();
        $this->createEvaluations();
        $this->createEvaluationCases();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_cases');
        Schema::dropIfExists('ai_evaluations');
        Schema::dropIfExists('ai_conversation_turns');
        Schema::dropIfExists('ai_conversations');
    }

    private function createConversations(): void
    {
        if (Schema::hasTable('ai_conversations')) {
            return;
        }

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();

            // Given by the client so a browser can resume a conversation it started
            // before the first turn was saved. Unique per organisation rather than
            // globally: two organisations generating the same uuid must not collide,
            // and a client-supplied id is not something to trust for uniqueness.
            $table->string('session_key', 64)->index();

            $table->string('title', 200)->nullable();
            // Which part of the product the question came from, where the caller says.
            // Null is honest for the global assistant panel, which belongs to none.
            $table->string('module_key', 60)->nullable()->index();

            $table->unsignedInteger('turn_count')->default(0);
            $table->string('status', 24)->default('active')->index();   // active | closed

            // Denormalised so the console can order by recency without touching the
            // turns table. Counting and max()-ing over turns for every row of a list
            // is the query that makes a conversation list slow.
            $table->timestamp('last_turn_at')->nullable()->index();

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['session_key', 'sub_institute_id'], 'ai_conversations_session_tenant_unique');
        });
    }

    private function createConversationTurns(): void
    {
        if (Schema::hasTable('ai_conversation_turns')) {
            return;
        }

        Schema::create('ai_conversation_turns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();

            // Ordered explicitly rather than by id, so a turn inserted out of sequence
            // by a retry does not silently reorder a transcript.
            $table->unsignedInteger('turn_index')->default(0);
            $table->string('role', 16);                                  // user | assistant
            $table->longText('content');

            // What actually answered. Recorded per turn, not per conversation: a model
            // can be reconfigured mid-conversation, and "which model said this" is the
            // first question asked about a bad answer.
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('finish_reason', 40)->nullable();

            // Set when the turn failed. A conversation that records only its successes
            // reads as though the failures never happened.
            $table->text('error')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamps();

            $table->index(['conversation_id', 'turn_index'], 'ai_turns_conversation_order_idx');
        });
    }

    private function createEvaluations(): void
    {
        if (Schema::hasTable('ai_evaluations')) {
            return;
        }

        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // What is under test. A template key rather than an id, because an
            // evaluation outlives any one version of the template it measured — and
            // comparing versions is the whole reason to keep the history.
            $table->string('template_key', 120)->nullable()->index();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('module_key', 60)->nullable()->index();

            // Resolved at run time and frozen here, so a later configuration change
            // cannot rewrite what this run was measuring.
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();

            // draft: cases being written. running / completed / failed: a run.
            $table->string('status', 24)->default('draft')->index();

            $table->unsignedInteger('case_count')->default(0);
            $table->unsignedInteger('passed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            // Mean of the per-case scores, 0.0000-1.0000. Null until a run finishes;
            // zero would claim a measured result of nothing.
            $table->decimal('score', 5, 4)->nullable();

            $table->unsignedInteger('total_input_tokens')->default(0);
            $table->unsignedInteger('total_output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createEvaluationCases(): void
    {
        if (Schema::hasTable('ai_evaluation_cases')) {
            return;
        }

        Schema::create('ai_evaluation_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluation_id')->index();

            $table->string('label', 200);
            // The variable bag this case supplies to the template, as JSON. This is
            // the input side of the test and the reason a run is reproducible.
            $table->json('variables')->nullable();

            // What a correct answer must contain. Kept as a list of required phrases
            // rather than an exact expected string: an exact match against generated
            // prose fails on wording that is entirely correct, which would make every
            // score meaningless.
            $table->json('expect_contains')->nullable();
            // ...and what it must not. The more useful half in practice — "must not
            // invent a number", "must not name an employee".
            $table->json('expect_absent')->nullable();

            $table->longText('output')->nullable();
            $table->decimal('score', 5, 4)->nullable();
            $table->boolean('passed')->nullable();
            // Which expectation decided it, in words. A score with no reason attached
            // cannot be argued with, and an evaluation nobody can argue with does not
            // get trusted.
            $table->text('verdict')->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamps();

            $table->index(['evaluation_id', 'sort_order'], 'ai_eval_cases_order_idx');
        });
    }
};

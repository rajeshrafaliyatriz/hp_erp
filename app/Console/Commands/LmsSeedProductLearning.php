<?php

namespace App\Console\Commands;

use App\Services\Lms\EnrolmentWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put the two product-website cards ("G2G Sales", "ScholarClone Sales") on My Learning for a tenant.
 *
 * ── WHAT THEY ARE ───────────────────────────────────────────────────────────
 *
 * A card, not a course. Each opens the ORIGINAL product page (g2gf/public/learning/product-learning/*.html) as a normal browser page.
 * There are no lessons, no quiz, no progress and no completion, so this command writes only:
 *
 *   - one sub_std_map row per product (so the card exists), and
 *   - one lms_course_enroll row per learner (so the card is theirs to see, the way every course is).
 *
 * The frontend recognises the card by subject_category = 'Product Learning' and picks the page from the product named in the title.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────────
 *
 *  - Tenant and learners are ALWAYS passed in. Nothing is guessed and nothing is hard-coded.
 *  - Dry run by default. Nothing is written without --execute.
 *  - Idempotent. A card that exists (under its current or a legacy name) is updated in place, so its id and enrolments survive.
 *  - It never deletes anything. Chapters, lessons or quizzes an earlier version of this command created are left exactly as they are.
 *  - Enrolment goes through EnrolmentWriter, the one place that writes lms_course_enroll: it checks that the course and the user belong
 *    to the tenant, never downgrades a status, and never duplicates a row.
 *
 *   php artisan lms:seed-product-learning --database=live --tenant=N
 *   php artisan lms:seed-product-learning --database=live --tenant=N --learner=12,34 --execute
 *   php artisan lms:seed-product-learning --database=live --tenant=N --all-learners --product=g2g --execute
 */
class LmsSeedProductLearning extends Command
{
    protected $signature = 'lms:seed-product-learning
        {--database=mysql : Connection to write}
        {--tenant= : sub_institute_id that will own the cards (required)}
        {--product=all : g2g, scholarclone or all}
        {--learner= : Comma-separated tbluser ids to enrol}
        {--all-learners : Enrol every active user of the tenant}
        {--execute : Actually write. Without it, nothing is changed}';

    protected $description = 'Add the G2G and ScholarClone product-website cards to My Learning and optionally enrol learners';

    /** The picker recognises the cards by this category; it must match PRODUCT_LEARNING_CATEGORY in the frontend. */
    private const CATEGORY = 'Product Learning';

    private const PRODUCTS = ['g2g', 'scholarclone'];

    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $tenant = (int) $this->option('tenant');
        $execute = (bool) $this->option('execute');
        $which = strtolower((string) $this->option('product'));

        if ($tenant <= 0) {
            $this->error('--tenant is required: the cards are written to one organisation.');

            return self::FAILURE;
        }

        if ($which !== 'all' && !in_array($which, self::PRODUCTS, true)) {
            $this->error('--product must be g2g, scholarclone or all.');

            return self::FAILURE;
        }

        $db = DB::connection($connection);

        // A tenant is real when it has users (tbluser.sub_institute_id is the one tenant column every install has).
        if (!$db->table('tbluser')->where('sub_institute_id', $tenant)->exists()) {
            $this->error("Tenant {$tenant} was not found on '{$connection}'.");

            return self::FAILURE;
        }

        $learners = $this->resolveLearners($db, $tenant);

        if ($learners === null) {
            return self::FAILURE;
        }

        $this->info("Product website cards on '{$connection}', tenant {$tenant}");
        $this->line($execute ? '  MODE: writing' : '  MODE: dry run (pass --execute to write)');
        $this->line('  Learners to enrol: ' . (count($learners) ?: 'none (pass --learner or --all-learners)'));
        $this->newLine();

        $actor = $db->table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->whereIn('user_profile_id', function ($q) use ($tenant) {
                $q->select('id')->from('tbluserprofilemaster')
                    ->where('sub_institute_id', $tenant)->where('role_key', 'administrator');
            })->value('id');

        foreach (self::PRODUCTS as $order => $key) {
            if ($which !== 'all' && $which !== $key) {
                continue;
            }

            $def = require database_path("data/product-learning/{$key}.php");
            $existing = $this->find($db, $tenant, $def);
            $this->line("▸ {$def['name']}");

            if (!$execute) {
                $this->line('    card          ' . ($existing
                    ? "exists (#{$existing->id})" . ($existing->display_name !== $def['name'] ? ", would be renamed from \"{$existing->display_name}\"" : '')
                    : 'CREATE'));

                continue;
            }

            $courseId = $this->write($db, $tenant, $def, $order + 1, $actor, $existing);
            $enrolled = $this->enrol($connection, $tenant, $courseId, $learners);
            $this->line("    enrolled      {$enrolled} of " . count($learners) . ' learner(s)');
        }

        $this->newLine();

        if (!$execute) {
            $this->warn('Nothing was written. Re-run with --execute.');
        }

        return self::SUCCESS;
    }

    /** @return int[]|null null when an option was invalid */
    private function resolveLearners($db, int $tenant): ?array
    {
        $ids = [];

        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('learner')))) as $id) {
            if (!ctype_digit($id)) {
                $this->error("--learner must be a comma-separated list of user ids; got '{$id}'.");

                return null;
            }
            $ids[] = (int) $id;
        }

        if ($this->option('all-learners')) {
            $query = $db->table('tbluser')->where('sub_institute_id', $tenant);

            if (Schema::connection($db->getName())->hasColumn('tbluser', 'status')) {
                $query->where('status', 1);
            }

            $ids = array_merge($ids, $query->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($ids));
    }

    /** The existing card for this product, under its current name or a legacy one (current name wins). */
    private function find($db, int $tenant, array $def): ?object
    {
        return $db->table('sub_std_map')
            ->where('sub_institute_id', $tenant)
            ->whereIn('display_name', array_merge([$def['name']], $def['legacy_names'] ?? []))
            ->whereNull('deleted_at')
            ->orderByRaw('display_name = ? desc', [$def['name']])
            ->first(['id', 'display_name']);
    }

    private function write($db, int $tenant, array $def, int $sortOrder, $actor, ?object $existing): int
    {
        $now = now();

        if ($existing) {
            // Applies a renamed title in place, so the id and every enrolment survive.
            $db->table('sub_std_map')->where('id', $existing->id)->update([
                'display_name' => $def['name'],
                'subject_category' => self::CATEGORY,
                'updated_at' => $now,
            ]);
            $this->line("    card          updated (#{$existing->id})");

            return (int) $existing->id;
        }

        $courseId = (int) $db->table('sub_std_map')->insertGetId([
            'display_name' => $def['name'],
            'standard_id' => null,
            'subject_category' => self::CATEGORY,
            'subject_type' => 'Product website',
            'sort_order' => $sortOrder,
            'status' => 1,
            'sub_institute_id' => $tenant,
            'allow_grades' => 'Yes',
            'allow_content' => 'Yes',
            'elective_subject' => 'No',
            'add_content' => 'chapterwise',
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->line("    card          created (#{$courseId})");

        return $courseId;
    }

    /** @param int[] $learners */
    private function enrol(string $connection, int $tenant, int $courseId, array $learners): int
    {
        $writer = app(EnrolmentWriter::class)->on($connection);
        $enrolled = 0;

        foreach ($learners as $userId) {
            if ($writer->ensureEnrolment($userId, $courseId, $tenant) !== null) {
                $enrolled++;
            }
        }

        return $enrolled;
    }
}

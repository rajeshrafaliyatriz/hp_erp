<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the invented example rows that earlier migrations and seeders put under the AI Stack tabs.
 *
 * WHAT IT REMOVES
 *
 * Platform-level rows (no organisation, no author) whose CONTENT was written by a developer as a worked
 * example, not by an administrator as a real setting:
 *
 *   ai_policies             name ending "(example)", with their rules, assignments and acknowledgements
 *   ai_templates            name ending "(example)" / "(starter example)", or key `g2g.example.*`
 *   ai_suggestions          chat suggestions pointing at a removed template
 *
 * Every marker has to match: platform scope AND no creator AND the example wording. A row an administrator
 * saved, edited or created in the UI is never selected, and neither is the real `g2g.field_edit` template.
 *
 * WHAT IT DOES NOT TOUCH
 *
 * Generated reports (real output) are kept; only their `layout_template_id` pointer to a removed template is
 * cleared, and the report text is left as it was. Agents and their runs are real executions and stay.
 *
 * SAFE: dry-run unless --execute; every deleted row is written in full to a backup file first, and
 * `--restore=<file>` puts them back with their original ids.
 */
class AiRemoveSeededExamples extends Command
{
    protected $signature = 'ai:remove-seeded-examples {--execute : Delete (without it nothing changes)} {--restore= : Re-insert the rows saved in a backup file}';

    protected $description = 'Remove developer-written example policies, templates and suggestions from the AI Stack (backed up, restorable)';

    public function handle(): int
    {
        if ($this->option('restore')) {
            return $this->restore((string) $this->option('restore'));
        }

        $policies = $this->policies();
        $templates = $this->templates();
        $keys = $templates->pluck('template_key')->filter()->all();
        $suggestions = Schema::hasTable('ai_suggestions')
            ? DB::table('ai_suggestions')->whereNull('sub_institute_id')->whereIn('action_ref', $keys)->get()
            : collect();
        $policyIds = $policies->pluck('id')->all();
        $templateIds = $templates->pluck('id')->all();

        $related = [
            'ai_policy_rules' => DB::table('ai_policy_rules')->whereIn('policy_id', $policyIds)->get(),
            'ai_policy_assignments' => DB::table('ai_policy_assignments')->whereIn('policy_id', $policyIds)->get(),
            'ai_policy_acknowledgements' => Schema::hasTable('ai_policy_acknowledgements')
                ? DB::table('ai_policy_acknowledgements')->whereIn('policy_id', $policyIds)->get() : collect(),
        ];
        $reports = Schema::hasTable('ai_generated_reports')
            ? DB::table('ai_generated_reports')->whereIn('layout_template_id', $templateIds)->count() : 0;

        $this->table(['Table', 'Rows'], [
            ['ai_policies', $policies->count()],
            ['ai_policy_rules', $related['ai_policy_rules']->count()],
            ['ai_policy_assignments', $related['ai_policy_assignments']->count()],
            ['ai_policy_acknowledgements', $related['ai_policy_acknowledgements']->count()],
            ['ai_templates', $templates->count()],
            ['ai_suggestions', $suggestions->count()],
            ['ai_generated_reports (pointer cleared only)', $reports],
        ]);

        foreach ($policies as $p) {
            $this->line("  policy   #{$p->id} {$p->name}");
        }
        foreach ($templates as $t) {
            $this->line("  template #{$t->id} {$t->name} [{$t->template_key}]");
        }

        if (! $this->option('execute')) {
            $this->warn('Dry run. Nothing changed. Re-run with --execute to delete (a backup file is written first).');

            return self::SUCCESS;
        }

        $backup = storage_path('app/ai-seed-removal-' . now()->format('Ymd-His') . '.json');
        File::ensureDirectoryExists(dirname($backup));
        File::put($backup, json_encode([
            'ai_policies' => $policies->all(),
            'ai_policy_rules' => $related['ai_policy_rules']->all(),
            'ai_policy_assignments' => $related['ai_policy_assignments']->all(),
            'ai_policy_acknowledgements' => $related['ai_policy_acknowledgements']->all(),
            'ai_templates' => $templates->all(),
            'ai_suggestions' => $suggestions->all(),
            'report_pointers' => Schema::hasTable('ai_generated_reports')
                ? DB::table('ai_generated_reports')->whereIn('layout_template_id', $templateIds)->get(['id', 'layout_template_id'])->all() : [],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->info("Backup written: {$backup}");

        DB::transaction(function () use ($policyIds, $templateIds, $suggestions, $reports) {
            if ($reports > 0) {
                DB::table('ai_generated_reports')->whereIn('layout_template_id', $templateIds)->update(['layout_template_id' => null]);
            }
            DB::table('ai_suggestions')->whereIn('id', $suggestions->pluck('id')->all())->delete();
            foreach (['ai_policy_acknowledgements', 'ai_policy_assignments', 'ai_policy_rules'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('policy_id', $policyIds)->delete();
                }
            }
            DB::table('ai_policies')->whereIn('id', $policyIds)->delete();
            DB::table('ai_templates')->whereIn('id', $templateIds)->delete();
        });

        $this->info('Removed.');

        return self::SUCCESS;
    }

    private function policies()
    {
        return DB::table('ai_policies')
            ->whereNull('sub_institute_id')
            ->whereNull('created_by')
            ->where('name', 'like', '% (example)')
            ->get();
    }

    private function templates()
    {
        return DB::table('ai_templates')
            ->whereNull('sub_institute_id')
            ->whereNull('created_by')
            ->where(fn ($q) => $q
                ->where('name', 'like', '% (example)')
                ->orWhere('name', 'like', '% (starter example)')
                ->orWhere('template_key', 'like', 'g2g.example.%'))
            ->get();
    }

    private function restore(string $file): int
    {
        if (! File::exists($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $data = json_decode(File::get($file), true);

        DB::transaction(function () use ($data) {
            foreach (['ai_policies', 'ai_templates', 'ai_policy_rules', 'ai_policy_assignments', 'ai_policy_acknowledgements', 'ai_suggestions'] as $table) {
                foreach ($data[$table] ?? [] as $row) {
                    if (! DB::table($table)->where('id', $row['id'])->exists()) {
                        DB::table($table)->insert($row);
                    }
                }
            }
            foreach ($data['report_pointers'] ?? [] as $p) {
                DB::table('ai_generated_reports')->where('id', $p['id'])->update(['layout_template_id' => $p['layout_template_id']]);
            }
        });

        $this->info('Restored.');

        return self::SUCCESS;
    }
}

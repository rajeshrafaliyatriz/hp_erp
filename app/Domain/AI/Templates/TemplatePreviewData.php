<?php

namespace App\Domain\AI\Templates;

use App\Domain\AI\Support\ActiveRowFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The values Preview substitutes into a template, read from this organisation's own
 * records.
 *
 * WHY REAL DATA RATHER THAN A FIXED SAMPLE
 *
 * LMS K-12's preview uses invented rows, and for its purposes that is defensible: the
 * question an author is asking is "did my placeholder land where I meant it to", and
 * any text answers that. But it leaves the more important question unanswered — does
 * the prompt read well around the data it will actually receive. An author writing
 * "list each student's outstanding balance" against three fake rows cannot see that
 * their own records have no such field.
 *
 * So the preview here substitutes the signed-in organisation's real competency
 * records. The author sees their own codes, their own criticality bands, their own
 * counts, and a prompt that assumes a field they do not have fails visibly at the
 * moment of writing rather than at the moment somebody reads a generated summary.
 *
 * TAXONOMY, NEVER A PERSON
 *
 * Only `competency`, `s_competency_frameworks` and `jobrole_competency_map` are read.
 * All three describe the organisation's capability model — what competencies exist,
 * how they are grouped, which roles require them. None of them names an employee, and
 * nothing here reads a rating, an assessment or a user row. That boundary is the
 * reason this is safe to render on a settings screen: an administrator previewing a
 * template is not a reason to put somebody's competency rating in front of them, and
 * the tables that hold those are deliberately absent from this file.
 *
 * SCOPE COMES FROM THE CALLER'S TOKEN
 *
 * `$institute` is `AiRequestScope::selectedInstituteId`, never request input. An
 * organisation previews against its own records or against none.
 *
 * DEGRADES TO AN ILLUSTRATION
 *
 * A new organisation has no competencies yet, and a preview showing nothing would be
 * worse than one showing something: the author could not tell an empty organisation
 * from a broken placeholder. So the fallback is the same obviously-illustrative set
 * LMS uses, and `data_source` says which of the two the author is looking at.
 */
final class TemplatePreviewData
{
    /** How many records a preview shows. Enough to see the shape, short enough to read. */
    private const ROWS = 3;

    /**
     * The variable bag, keyed by the names in `TemplateVariableCatalog`.
     *
     * @return array<string, string>
     */
    public function forInstitute(int|string|null $institute): array
    {
        try {
            $live = $this->fromCompetencies($institute);
        } catch (Throwable) {
            // A preview is not worth failing a request over. An unreadable table
            // falls back to the illustration rather than 500-ing the editor.
            $live = null;
        }

        return $live ?? $this->illustration();
    }

    /**
     * This organisation's real capability model, or null when it has none.
     *
     * @return array<string, string>|null
     */
    private function fromCompetencies(int|string|null $institute): ?array
    {
        if ($institute === null || trim((string) $institute) === '' || ! Schema::hasTable('competency')) {
            return null;
        }

        $query = DB::table('competency')->where('sub_institute_id', $institute);

        // NOT `where('status', 1)`. `competency.status` is a varchar holding
        // 'active' | 'draft' | 'published', so comparing it to the integer 1 matched
        // nothing and this method fell back to its illustration for an organisation
        // holding 199 competencies. See ActiveRowFilter.
        ActiveRowFilter::apply($query, 'competency');

        $rows = $query->orderBy('name')->limit(self::ROWS)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = (int) DB::table('competency')->where('sub_institute_id', $institute)->count();
        $frameworks = $this->countFor('s_competency_frameworks', $institute);
        $mappings = $this->countFor('jobrole_competency_map', $institute);

        return [
            'records' => $rows->map(fn ($row) => $this->describe($row))->implode("\n"),
            'metrics' => sprintf(
                'Competencies: %d; Frameworks: %d; Job-role requirements mapped: %d',
                $total,
                $frameworks,
                $mappings
            ),
            'record_count' => (string) $total,
            'rows_shown' => (string) $rows->count(),
            // Honest: it is a window onto a larger set whenever it is one. This is the
            // variable a prompt needs to avoid describing three rows as though they
            // were the whole library.
            'is_partial' => $total > $rows->count() ? 'yes' : 'no',
            'page_title' => 'Competency library',
            'page_type' => 'list',
            'filters' => 'none',
            'search_query' => '',
            'data_source' => 'your organisation’s competency records',
            'module' => 'Capability Intelligence',
            'entity_label' => '',
        ];
    }

    /**
     * One record, in the shape the catalogue documents: `- Label (field: value, …)`.
     *
     * Only columns that are actually populated are listed. A row printed as
     * "(type: , criticality: )" teaches an author that those fields arrive empty,
     * which is a claim about their data rather than about this method.
     */
    private function describe(object $row): string
    {
        $fields = [];

        foreach (['code' => 'code', 'competency_type' => 'type', 'criticality' => 'criticality'] as $column => $label) {
            $value = trim((string) ($row->{$column} ?? ''));

            if ($value !== '') {
                $fields[] = "{$label}: {$value}";
            }
        }

        $name = trim((string) ($row->name ?? '')) ?: 'Unnamed competency';

        return $fields === []
            ? "- {$name}"
            : sprintf('- %s (%s)', $name, implode(', ', $fields));
    }

    private function countFor(string $table, int|string $institute): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sub_institute_id')) {
            return 0;
        }

        return (int) DB::table($table)->where('sub_institute_id', $institute)->count();
    }

    /**
     * The stand-in for an organisation with nothing recorded yet.
     *
     * Deliberately concrete and obviously invented — a preview of "lorem ipsum" shows
     * an author nothing about whether their prompt reads well, and one indistinguishable
     * from real data would be mistaken for it.
     *
     * @return array<string, string>
     */
    private function illustration(): array
    {
        return [
            'records' => "- Root Cause Analysis (code: EXAMPLE-1, type: technical, criticality: high)\n"
                . "- Statistical Process Control (code: EXAMPLE-2, type: technical, criticality: medium)\n"
                . '- Stakeholder Communication (code: EXAMPLE-3, type: behavioural, criticality: medium)',
            'metrics' => 'Competencies: 3; Frameworks: 1; Job-role requirements mapped: 3',
            'record_count' => '3',
            'rows_shown' => '3',
            'is_partial' => 'no',
            'page_title' => 'Competency library',
            'page_type' => 'list',
            'filters' => 'none',
            'search_query' => '',
            // Named so an author is never in doubt about which of the two they are
            // reading.
            'data_source' => 'example records (this organisation has none yet)',
            'module' => 'Capability Intelligence',
            'entity_label' => '',
        ];
    }
}

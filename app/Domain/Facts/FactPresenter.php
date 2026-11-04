<?php

namespace App\Domain\Facts;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the Fact shape of the API (slice 4, docs/agentic-crm/slices/S04-contact-facts-and-consent.md "Contract"). Every lookup carries the tenant, so a
 * deciding person of another organisation is never named whatever a stray pointer says, and evidence that belongs to another organisation never appears.
 *
 * `value` and every text of the evidence may have come from the web: they are returned as plain text for the screen to render as text, never as markup.
 */
final class FactPresenter
{
    public function __construct(private readonly int $tenantId)
    {
    }

    /**
     * @param  list<object>  $rows  g2g_entity_facts rows of this tenant
     * @return list<array<string, mixed>>
     */
    public function facts(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $evidence = DB::table('g2g_entity_fact_evidence')->where('sub_institute_id', $this->tenantId)->whereIn('fact_id', array_map(fn ($r) => (int) $r->id, $rows))->orderBy('id')->get()->groupBy('fact_id');
        $names = $this->userNames(array_map(fn ($r) => $r->decided_by, $rows));
        $held = $this->recordValues($rows);

        return array_map(fn ($row) => $this->fact($row, ($evidence[$row->id] ?? collect())->all(), $names, $held["{$row->entity_type}:{$row->entity_id}:{$row->field}"] ?? null), $rows);
    }

    /**
     * @param  list<object>  $evidence
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function fact(object $row, array $evidence, array $names, ?string $recordValue): array
    {
        $items = array_map(fn ($e) => ['kind' => $e->kind, 'label' => EvidenceScorer::label((string) $e->kind), 'weight' => (float) $e->weight, 'source_url' => $e->source_url,
            'quote' => $e->quote, 'retrieved_at' => $this->date($e->retrieved_at)], $evidence);

        return [
            'id' => (int) $row->id, 'entity_type' => $row->entity_type, 'entity_id' => (int) $row->entity_id, 'field' => $row->field,
            'field_label' => (string) config("facts.fields.{$row->field}.label", $row->field), 'value' => (string) $row->value, 'value_kind' => $row->value_kind, 'status' => $row->status,
            'band' => $row->band, 'score' => (float) $row->score, 'method' => $row->method, 'source_url' => $row->source_url, 'proposed_by' => $row->proposed_by,
            'decided_by_name' => $row->decided_by !== null ? ($names[(int) $row->decided_by] ?? null) : null, 'decided_at' => $this->date($row->decided_at),
            'decision_note' => $row->decision_note, 'column_written' => (bool) $row->column_written, 'record_value' => $recordValue, 'supersedes_id' => $row->supersedes_id !== null ? (int) $row->supersedes_id : null,
            'created_at' => $this->date($row->created_at), 'rationale' => $this->rationale($evidence), 'evidence' => $items,
        ];
    }

    /** @param list<object> $evidence */
    private function rationale(array $evidence): string
    {
        try {
            return EvidenceScorer::score(array_map(fn ($e) => ['kind' => $e->kind, 'quote' => $e->quote], $evidence))['rationale'];
        } catch (\InvalidArgumentException) {
            return '';   // a kind that has since left the weights table: the stored labels above still say what was seen
        }
    }

    /**
     * Who decided, by name: only people of THIS organisation.
     *
     * @param  list<int|string|null>  $ids
     * @return array<int, string>
     */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, fn ($id) => $id !== null)))));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (DB::table('tbluser')->where('sub_institute_id', $this->tenantId)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $user) {
            $names[(int) $user->id] = trim(((string) $user->first_name) . ' ' . ((string) $user->last_name)) ?: 'User ' . $user->id;
        }

        return $names;
    }

    /**
     * What the record's own column holds now, for each fact whose field has a column on that kind of record: "type:id:field" => value. A person reads the
     * suggestion with the record's value beside it.
     *
     * @param  list<object>  $rows
     * @return array<string, string>
     */
    private function recordValues(array $rows): array
    {
        $wanted = [];
        foreach ($rows as $row) {
            $column = FactEntities::targetColumn((string) $row->entity_type, (string) $row->field);
            if ($column !== null) {
                $wanted[$row->entity_type][$column][(int) $row->entity_id] = true;
            }
        }
        $held = [];
        foreach ($wanted as $type => $columns) {
            $table = FactEntities::table($type);
            foreach ($columns as $column => $ids) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                foreach (DB::table($table)->where('sub_institute_id', $this->tenantId)->whereIn('id', array_keys($ids))->get(['id', $column]) as $record) {
                    $value = trim((string) ($record->{$column} ?? ''));
                    foreach ($rows as $row) {
                        if ($row->entity_type === $type && (int) $row->entity_id === (int) $record->id && FactEntities::targetColumn($type, (string) $row->field) === $column) {
                            $held["{$type}:{$record->id}:{$row->field}"] = $value;
                        }
                    }
                }
            }
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $held);
    }

    private function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}

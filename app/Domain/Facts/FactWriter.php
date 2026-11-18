<?php

namespace App\Domain\Facts;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE ONE WRITE PATH of the facts ledger (slice 4): a fact is PROPOSED here, and a person ACCEPTS or DISMISSES it here. Nothing else writes
 * g2g_entity_facts, g2g_entity_fact_evidence, or a record's column on a fact's behalf. The agent tool of slice 7 and the discovery sources of slice 5 call
 * propose() and nothing else; the Suggestions screen calls accept() and dismiss() through FactsController.
 *
 * What it promises, each one proved by a test and a deliberate breakage of the code (docs/agentic-crm/_evidence/s4-facts-ledger/):
 *   - a proposal is priced from its EVIDENCE (EvidenceScorer); below the lowest floor it is not stored; the caller never sets a score;
 *   - a value exists ONCE per record and field (unique key on the canonical value's hash): a dismissed value is never offered again, a value already
 *     waiting is not offered twice, an applied or replaced one is not offered again;
 *   - NOTHING is applied by a proposal (decision D3: no autonomous application of a fact in this slice); a person accepts it;
 *   - accepting applies the fact EXACTLY ONCE (a conditional UPDATE claims it, like ProposalApplier: two clicks race and one wins) and writes the record's own
 *     column ONLY WHEN THAT COLUMN IS BLANK, with a conditional UPDATE again, so a value a person typed between the read and the write is never overwritten;
 *   - a fact whose field has one current value (`one` in config/facts.php) supersedes the previously applied fact in the ledger, with a pointer, so a job
 *     change is a visible chain and not an overwrite;
 *   - every proposal and every decision writes its event in the same transaction as the state change.
 *
 * The value of a fact may have come from the web. It is DATA: checked against its field type (FactValue), stored and returned as text.
 */
final class FactWriter
{
    public const STORED = 'stored';

    public const REFUSED_DISMISSED = 'refused_dismissed';

    public const REFUSED_WAITING = 'refused_waiting';

    public const REFUSED_ON_RECORD = 'refused_on_record';

    public const REFUSED_REPLACED = 'refused_replaced';

    public const REFUSED_BELOW_FLOOR = 'refused_below_floor';

    public const REFUSED_VALUE = 'refused_value';

    public const REFUSED_FIELD = 'refused_field';

    public const REFUSED_EVIDENCE = 'refused_evidence';

    public const REFUSED_ENTITY = 'refused_entity';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    public const NOT_PENDING = 'not_pending';

    public const ENTITY_GONE = 'entity_gone';

    private const REFUSALS_BY_STATUS = [
        'dismissed' => [self::REFUSED_DISMISSED, 'A person has already dismissed this exact value. It is not offered again.'],
        'proposed' => [self::REFUSED_WAITING, 'This exact value is already in front of a person, waiting on them. Offering it twice only makes them read it twice.'],
        'applied' => [self::REFUSED_ON_RECORD, 'This exact value is already on the record as an accepted fact. Nothing changed.'],
        'superseded' => [self::REFUSED_REPLACED, 'This value was accepted before and has since been replaced by a newer one. It is not offered again.'],
    ];

    public function __construct(private readonly FactEvents $events)
    {
    }

    /**
     * Proposes a fact. It is stored as `proposed` and waits for a person.
     *
     * @param  list<array{kind: string, source_url?: ?string, quote?: ?string, detail?: ?string, retrieved_at?: ?string}>  $evidence  what was SEEN, one entry per independent source
     * @param  string  $method  how it was found, a short key (at most 30 characters)
     * @param  string  $proposedBy  who or what proposed it, e.g. `agent:organisation`, `user:12`, `import` (at most 40 characters)
     * @return array{stored: bool, outcome: string, fact: ?object, band: ?string, score: float, rationale: string, reason: ?string}
     */
    public function propose(int $tenantId, string $entityType, int $entityId, string $field, string $value, array $evidence, string $method, string $proposedBy, ?string $sourceUrl = null, ?int $actorId = null): array
    {
        $refuse = fn (string $outcome, string $reason, array $scored = []) => ['stored' => false, 'outcome' => $outcome, 'fact' => null, 'band' => $scored['band'] ?? null,
            'score' => (float) ($scored['score'] ?? 0.0), 'rationale' => (string) ($scored['rationale'] ?? ''), 'reason' => $reason];

        $config = config("facts.fields.{$field}");
        if (! FactEntities::isType($entityType) || ! is_array($config) || ! FactEntities::applies($entityType, $field)) {
            return $refuse(self::REFUSED_FIELD, "'{$field}' is not a field a fact can be about for a {$entityType}.");
        }
        $entity = FactEntities::find($tenantId, $entityType, $entityId);
        if ($entity === null) {
            return $refuse(self::REFUSED_ENTITY, "No such {$entityType}.");
        }

        try {
            $normal = FactValue::normalise((string) $config['type'], $value, (int) $config['max']);
        } catch (InvalidFactValue $e) {
            return $refuse(self::REFUSED_VALUE, $e->getMessage());
        }

        $problem = $this->evidenceProblem($evidence, $sourceUrl);
        if ($problem !== null) {
            return $refuse(self::REFUSED_EVIDENCE, $problem);
        }
        $items = EvidenceScorer::independent($evidence);
        $scored = EvidenceScorer::score($items);
        if ($scored['band'] === null) {
            return $refuse(self::REFUSED_BELOW_FLOOR, 'Below the floor for keeping: not stored. Find a source that identifies them, or leave the field alone.', $scored);
        }

        $hash = FactValue::hash($normal['canonical']);
        $existing = $this->existing($tenantId, $entityType, $entityId, $field, $hash);
        if ($existing !== null) {
            [$outcome, $reason] = self::REFUSALS_BY_STATUS[$existing->status] ?? [self::REFUSED_WAITING, 'This value is already in the ledger.'];

            return $refuse($outcome, $reason, $scored);
        }
        if ($this->recordHolds($entityType, $entity, $field, $config, $normal['canonical'])) {
            return $refuse(self::REFUSED_ON_RECORD, 'The record already holds exactly this value.', $scored);
        }

        $now = now();
        $row = [
            'sub_institute_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId, 'field' => $field, 'value' => $normal['display'], 'value_hash' => $hash,
            'value_kind' => $config['type'] === 'email' ? FactValue::emailKind($normal['display']) : null, 'status' => 'proposed', 'band' => $scored['band'], 'score' => $scored['score'],
            'method' => mb_substr(trim($method), 0, 30), 'source_url' => $sourceUrl ?? ($items[0]['source_url'] ?? null), 'proposed_by' => mb_substr(trim($proposedBy), 0, 40),
            'column_written' => 0, 'created_at' => $now, 'updated_at' => $now,
        ];

        try {
            $factId = DB::transaction(function () use ($row, $items, $tenantId, $actorId, $now, $scored) {
                $id = (int) DB::table('g2g_entity_facts')->insertGetId($row);
                foreach ($items as $item) {
                    DB::table('g2g_entity_fact_evidence')->insert([
                        'sub_institute_id' => $tenantId, 'fact_id' => $id, 'kind' => $item['kind'], 'weight' => EvidenceScorer::weightOf((string) $item['kind']),
                        'source_url' => $item['source_url'] ?? null, 'quote' => $this->quote($item), 'retrieved_at' => ($item['retrieved_at'] ?? '') !== '' ? date('Y-m-d H:i:s', (int) strtotime((string) $item['retrieved_at'])) : $now, 'created_at' => $now,
                    ]);
                }
                $this->events->proposed($tenantId, $id, $actorId, ['entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id'], 'field' => $row['field'],
                    'band' => $scored['band'], 'score' => $scored['score'], 'method' => $row['method'], 'proposed_by' => $row['proposed_by']]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request stored the same value between the read above and this insert. Say what the unique key says.
            $raced = $this->existing($tenantId, $entityType, $entityId, $field, $hash);
            if ($raced === null) {
                throw new \RuntimeException('A unique key refused a fact that is not in the ledger.');
            }

            [$outcome, $reason] = self::REFUSALS_BY_STATUS[$raced->status] ?? [self::REFUSED_WAITING, 'This value is already in the ledger.'];

            return $refuse($outcome, $reason, $scored);
        }

        return ['stored' => true, 'outcome' => self::STORED, 'fact' => DB::table('g2g_entity_facts')->find($factId), 'band' => $scored['band'], 'score' => $scored['score'],
            'rationale' => $scored['rationale'], 'reason' => null];
    }

    /**
     * A person accepts a proposed fact. Applied EXACTLY ONCE; writes the record's column only when it is blank.
     *
     * @return array{outcome: string, fact: object, column_written: bool}  outcome: applied | not_pending (already decided; nothing changed) | entity_gone (the record was removed; nothing changed)
     *
     * @throws FactNotFound
     */
    public function accept(int $tenantId, int $factId, int $actorId): array
    {
        $fact = $this->find($tenantId, $factId);
        if ($fact->status !== 'proposed') {
            return ['outcome' => self::NOT_PENDING, 'fact' => $fact, 'column_written' => (bool) $fact->column_written];
        }
        // Read BEFORE the transaction opens, so the race a test needs to stage (another request deciding the fact between this read and the claim) is a
        // race between two connections and not one inside this one's transaction.
        $entity = FactEntities::find($tenantId, (string) $fact->entity_type, (int) $fact->entity_id);
        if ($entity === null) {
            return ['outcome' => self::ENTITY_GONE, 'fact' => $fact, 'column_written' => false];
        }

        try {
            DB::transaction(function () use ($tenantId, $fact, $entity, $actorId) {
                $now = now();
                $claimed = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('id', $fact->id)->where('status', 'proposed')
                    ->update(['status' => 'applied', 'decided_by' => $actorId, 'decided_at' => $now, 'updated_at' => $now]);
                if ($claimed !== 1) {
                    throw new LostTheFactClaim();
                }

                $written = $this->writeThrough($tenantId, $fact, $entity, $actorId);
                $superseded = $this->supersede($tenantId, $fact);
                DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('id', $fact->id)
                    ->update(['column_written' => $written ? 1 : 0, 'supersedes_id' => $superseded === [] ? null : max($superseded)]);

                $this->events->decided($tenantId, (int) $fact->id, $actorId, ['decision' => 'accepted', 'entity_type' => $fact->entity_type, 'entity_id' => (int) $fact->entity_id,
                    'field' => $fact->field, 'column_written' => $written, 'superseded' => $superseded]);
            });
        } catch (LostTheFactClaim) {
            // Somebody else decided it first. Report what they did; this request changed nothing.
            $now = $this->find($tenantId, $factId);

            return ['outcome' => self::NOT_PENDING, 'fact' => $now, 'column_written' => (bool) $now->column_written];
        }

        $after = $this->find($tenantId, $factId);

        return ['outcome' => self::APPLIED, 'fact' => $after, 'column_written' => (bool) $after->column_written];
    }

    /**
     * A person dismisses a proposed fact. The value keeps its row (and so its place in the unique key): it is never offered again.
     *
     * @return array{outcome: string, fact: object}  outcome: dismissed | not_pending
     *
     * @throws FactNotFound
     */
    public function dismiss(int $tenantId, int $factId, int $actorId, ?string $reason = null): array
    {
        $fact = $this->find($tenantId, $factId);
        if ($fact->status !== 'proposed') {
            return ['outcome' => self::NOT_PENDING, 'fact' => $fact];
        }
        $note = $reason === null ? '' : trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]+/u', ' ', $reason)));
        $note = $note === '' ? null : mb_substr($note, 0, 500);

        try {
            DB::transaction(function () use ($tenantId, $fact, $actorId, $note) {
                $now = now();
                $claimed = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('id', $fact->id)->where('status', 'proposed')
                    ->update(['status' => 'dismissed', 'decided_by' => $actorId, 'decided_at' => $now, 'decision_note' => $note, 'updated_at' => $now]);
                if ($claimed !== 1) {
                    throw new LostTheFactClaim();
                }
                $this->events->decided($tenantId, (int) $fact->id, $actorId, ['decision' => 'dismissed', 'entity_type' => $fact->entity_type, 'entity_id' => (int) $fact->entity_id,
                    'field' => $fact->field, 'reason' => $note]);
            });
        } catch (LostTheFactClaim) {
            return ['outcome' => self::NOT_PENDING, 'fact' => $this->find($tenantId, $factId)];
        }

        return ['outcome' => self::DISMISSED, 'fact' => $this->find($tenantId, $factId)];
    }

    // ---- internals ----

    /** @throws FactNotFound */
    private function find(int $tenantId, int $factId): object
    {
        $fact = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('id', $factId)->first();
        if ($fact === null) {
            throw new FactNotFound();
        }

        return $fact;
    }

    private function existing(int $tenantId, string $type, int $id, string $field, string $hash): ?object
    {
        return DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('entity_type', $type)->where('entity_id', $id)->where('field', $field)->where('value_hash', $hash)->first() ?: null;
    }

    /**
     * Writes the fact's value into the record's own column ONLY when that column is blank. The UPDATE is conditional on the column still being blank, so a
     * value a person typed after the record was read is never overwritten. A field with no column on this kind of record (or a column this host does not
     * have yet) stays in the ledger only.
     */
    private function writeThrough(int $tenantId, object $fact, object $entity, int $actorId): bool
    {
        $column = FactEntities::targetColumn((string) $fact->entity_type, (string) $fact->field);
        $table = FactEntities::table((string) $fact->entity_type);
        if ($column === null || ! Schema::hasColumn($table, $column) || trim((string) ($entity->{$column} ?? '')) !== '') {
            return false;
        }

        $update = [$column => $fact->value];
        foreach (['updated_at' => now(), 'updated_by' => $actorId] as $name => $value) {
            if (Schema::hasColumn($table, $name)) {
                $update[$name] = $value;
            }
        }

        return DB::table($table)->where('id', $fact->entity_id)->where('sub_institute_id', $tenantId)
            ->where(fn ($blank) => $blank->whereNull($column)->orWhere($column, ''))->update($update) === 1;
    }

    /**
     * A field with ONE current value: the fact applied before this one is superseded, so the chain says how the value changed. A field that can hold several
     * values at once (two email addresses) supersedes nothing.
     *
     * @return list<int> the ids superseded
     */
    private function supersede(int $tenantId, object $fact): array
    {
        if (! (bool) config("facts.fields.{$fact->field}.one")) {
            return [];
        }
        $older = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('entity_type', $fact->entity_type)->where('entity_id', $fact->entity_id)
            ->where('field', $fact->field)->where('status', 'applied')->where('id', '!=', $fact->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($older !== []) {
            DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->whereIn('id', $older)->update(['status' => 'superseded', 'updated_at' => now()]);
        }

        return $older;
    }

    /** Whether the record's column already holds this value (compared as the field's type compares values, so "+91 98765 43210" is "+91-98765-43210"). */
    private function recordHolds(string $type, object $entity, string $field, array $config, string $canonical): bool
    {
        $column = FactEntities::targetColumn($type, $field);
        $held = $column === null ? '' : trim((string) ($entity->{$column} ?? ''));
        if ($held === '') {
            return false;
        }
        try {
            return FactValue::normalise((string) $config['type'], $held, 100000)['canonical'] === $canonical;
        } catch (InvalidFactValue) {
            return FactValue::canonical($held) === $canonical;
        }
    }

    /** @param list<array<string, mixed>> $evidence */
    private function evidenceProblem(array $evidence, ?string $sourceUrl): ?string
    {
        if ($sourceUrl !== null && ! $this->isWebAddress($sourceUrl)) {
            return 'The source must be a web address (http or https) of at most 1000 characters.';
        }
        foreach ($evidence as $item) {
            $kind = (string) ($item['kind'] ?? '');
            if (! EvidenceScorer::isKnown($kind)) {
                return "Unknown evidence kind '{$kind}'. Kinds: " . implode(', ', array_keys(EvidenceScorer::weights())) . '.';
            }
            $url = $item['source_url'] ?? null;
            if ($url !== null && $url !== '' && ! $this->isWebAddress((string) $url)) {
                return 'Every evidence source must be a web address (http or https) of at most 1000 characters.';
            }
            $seen = $item['retrieved_at'] ?? null;
            if ($seen !== null && $seen !== '' && strtotime((string) $seen) === false) {
                return 'The date a source was seen (retrieved_at) is not a date.';
            }
        }

        return null;
    }

    private function isWebAddress(string $url): bool
    {
        return mb_strlen($url) <= 1000 && preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** What was seen, as text a person can read: control characters out, at most 1000 characters. @param array<string, mixed> $item */
    private function quote(array $item): ?string
    {
        $text = (string) ($item['quote'] ?? $item['detail'] ?? '');
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]+/u', ' ', $text)));

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }
}

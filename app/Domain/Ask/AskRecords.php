<?php

namespace App\Domain\Ask;

use App\Support\ColumnExists;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read side of the four CRM record kinds (slice 8, Ask): leads, contacts, organisations, opportunities. READ ONLY, and every query carries the caller's
 * organisation and leaves out a record that was deleted, exactly as the record screens' own routes do (`sub_institute_id = tenant`, `deleted_at IS NULL`;
 * the lead list also hides converted leads). A record of another organisation, a deleted one and one that never existed are indistinguishable here: no row.
 *
 * WHAT THE MODEL IS SHOWN of a record is a short list of facts the person could read on the record's own page. Contact details (email addresses, phone
 * numbers, street addresses) are NOT sent: only whether an email or a phone is on file, as the Organisation agent does. A record's free text (description,
 * next step, the last few notes) is shortened and is untrusted: it reaches the model only inside the data block.
 *
 * HOSTS DIFFER (the live database lacks columns the dev one has): the columns a query names are asked about first, and a fact whose column is missing is left
 * out, never printed as empty.
 */
final class AskRecords
{
    public const TYPES = ['lead', 'contact', 'organization', 'opportunity'];

    /** The most lead sources the leads summary names (the leads with no source recorded come on top of these, labelled). */
    public const SOURCE_CAP = 10;

    public const NO_SOURCE_LABEL = 'not recorded';

    private const TABLES = ['lead' => 'crm_leads', 'contact' => 'crm_contacts', 'organization' => 'crm_organizations', 'opportunity' => 'crm_opportunities'];

    /** @var array<string, array{0: string, 1: string}> record kind => [the comments table, its pointer column] */
    private const NOTES = [
        'lead' => ['crm_lead_comments', 'lead_id'], 'contact' => ['crm_contact_comments', 'contact_id'],
        'organization' => ['crm_organization_comments', 'organization_id'], 'opportunity' => ['crm_opportunity_comments', 'opportunity_id'],
    ];

    /** @var array<string, list<string>> the columns a typed word is matched against: names, numbers, emails (the contract: "name, email, number") */
    private const SEARCH = [
        'lead' => ['first_name', 'last_name', 'company', 'email', 'secondary_email', 'lead_no', 'phone', 'mobile'],
        'contact' => ['first_name', 'last_name', 'email', 'secondary_email', 'contact_no', 'phone', 'mobile'],
        'organization' => ['name', 'account_no', 'email', 'website', 'phone'],
        'opportunity' => ['name', 'opportunity_no'],
    ];

    private const PAGES = [
        'lead' => '/module/crm/marketing/leads/', 'contact' => '/module/crm/marketing/contacts/',
        'organization' => '/module/crm/marketing/organizations/', 'opportunity' => '/module/crm/sales/opportunities/',
    ];

    /** @var array<string, list<string>> per table, so a request asks the database once */
    private static array $columns = [];

    /** @var array<string, bool> */
    private static array $tables = [];

    /** Forgets what was learned of the schema (the tests rebuild it between cases). */
    public static function forgetSchema(): void
    {
        self::$columns = [];
        self::$tables = [];
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        return self::$columns[$table] ??= array_map('strtolower', ColumnExists::all($table));
    }

    public static function page(string $type, int|string $id): string
    {
        return self::PAGES[$type] . $id;
    }

    public static function hasTable(string $type): bool
    {
        return self::$tables[$type] ??= Schema::hasTable(self::TABLES[$type]);
    }

    /** The caller's organisation's rows of this kind that are not deleted. */
    private static function base(AskCaller $caller, string $type): Builder
    {
        $table = self::TABLES[$type];
        $q = DB::table($table)->where('sub_institute_id', $caller->tenantId);
        if (in_array('deleted_at', self::columns($table), true)) {
            $q->whereNull('deleted_at');
        }

        return $q;
    }

    // ---- search ----

    /**
     * Records of one kind whose name, email or number contains every word typed (at most four words; wildcards typed by the person are escaped, so a `%`
     * matches a `%`). Newest first. Returns the first $limit rows and how many matched in all.
     *
     * @return array{rows: list<AskRow>, total: int}
     */
    public static function search(AskCaller $caller, string $type, string $text, int $limit): array
    {
        if (! self::hasTable($type)) {
            return ['rows' => [], 'total' => 0];
        }
        $table = self::TABLES[$type];
        $columns = array_values(array_intersect(self::SEARCH[$type], self::columns($table)));
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: [], fn ($w) => $w !== '')), 0, 4);
        if ($columns === [] || $words === []) {
            return ['rows' => [], 'total' => 0];
        }

        $q = self::base($caller, $type);
        if ($type === 'lead' && in_array('converted', self::columns($table), true)) {
            $q->where('converted', false);   // the lead list hides converted leads (a soft archive): so does the search
        }
        foreach ($words as $word) {
            $like = AskText::like($word);
            $q->where(function ($w) use ($columns, $like) {
                foreach ($columns as $column) {
                    $w->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        $total = (int) (clone $q)->count();
        $records = $q->orderByDesc('id')->limit($limit)->get();

        return ['rows' => self::rows($caller, $type, $records), 'total' => $total];
    }

    // ---- one record ----

    /** The record and its related rows, or null when it is not there for this caller (another organisation's, deleted, or never existed: the same answer). */
    public static function find(AskCaller $caller, string $type, int $id): ?object
    {
        if (! self::hasTable($type)) {
            return null;
        }

        return self::base($caller, $type)->where('id', $id)->first();
    }

    /**
     * The record's row with the extra facts only an opened record carries: its description, next step and last few notes.
     */
    public static function detail(AskCaller $caller, string $type, object $record): AskRow
    {
        $row = self::rows($caller, $type, [$record])[0];
        $data = $row->data;
        $max = (int) config('ask.max_text_chars', 300);
        $data['description'] = AskText::clip($record->description ?? null, $max);
        if ($type === 'opportunity') {
            $data['next_step'] = AskText::clip($record->next_step ?? null, $max);
        }
        $data['recent_notes'] = self::notes($caller, $type, (int) $record->id);

        return new AskRow($row->type, $row->id, $row->label, $row->url, $data);
    }

    /** @return list<array{written: ?string, text: ?string}> the newest notes of a record (text is untrusted) */
    private static function notes(AskCaller $caller, string $type, int $id): array
    {
        [$table, $pointer] = self::NOTES[$type];
        if (! Schema::hasTable($table)) {
            return [];
        }
        $out = [];
        foreach (DB::table($table)->where('sub_institute_id', $caller->tenantId)->where($pointer, $id)->orderByDesc('id')->limit((int) config('ask.max_notes', 3))->get(['comment', 'created_at']) as $note) {
            $out[] = ['written' => self::day($note->created_at), 'text' => AskText::clip($note->comment, (int) config('ask.max_text_chars', 300))];
        }

        return $out;
    }

    // ---- related rows (an opened organisation, opportunity, contact or lead) ----

    /**
     * What hangs off an opened record, each as a row of its own so the answer can cite it. A list the caller's profile may not open is not read: its kind is
     * named in `withheld` and the answer says so.
     *
     * @return array{rows: list<AskRow>, withheld: list<string>}
     */
    public static function related(AskCaller $caller, string $type, object $record): array
    {
        $rows = [];
        $withheld = [];
        $cap = (int) config('ask.max_related_rows', 5);
        $need = function (string $kind) use ($caller, &$withheld): bool {
            if (AskRights::canRead($caller, $kind)) {
                return true;
            }
            $withheld[] = AskRights::plural($kind);

            return false;
        };

        if ($type === 'organization') {
            if ($need('opportunity') && self::hasTable('opportunity')) {
                $q = self::base($caller, 'opportunity')->where('organization_id', $record->id);
                self::onlyOpen($q);
                array_push($rows, ...self::rows($caller, 'opportunity', $q->orderByDesc('id')->limit($cap)->get()));
            }
            if ($need('contact') && self::hasTable('contact')) {
                array_push($rows, ...self::rows($caller, 'contact', self::base($caller, 'contact')->where('organization_id', $record->id)->orderByDesc('id')->limit($cap)->get()));
            }
            array_push($rows, ...self::openTasks($caller, 'ORGANIZATION', (int) $record->id, self::page('organization', $record->id)));
            if ($need('signal')) {
                array_push($rows, ...AskSignals::forOrganization($caller, (int) $record->id, $cap));
            }
        } elseif ($type === 'opportunity' || $type === 'contact') {
            $orgId = (int) ($record->organization_id ?? 0);
            if ($orgId > 0 && $need('organization')) {
                $org = self::find($caller, 'organization', $orgId);
                if ($org !== null) {
                    $rows[] = self::rows($caller, 'organization', [$org])[0];
                }
            }
        } elseif ($type === 'lead') {
            $signalId = (int) ($record->source_company_opportunity_id ?? 0);
            if ($signalId > 0 && $need('signal')) {
                array_push($rows, ...AskSignals::byIds($caller, [$signalId]));
            }
        }

        return ['rows' => $rows, 'withheld' => array_values(array_unique($withheld))];
    }

    /** An open opportunity is one whose stage is not closed, won or lost (a deal with no stage is open): the rule the Organisation agent uses. */
    private static function onlyOpen(Builder $q): void
    {
        $q->where(function ($w) {
            $w->whereNull('sales_stage')->orWhere(function ($s) {
                foreach (['%closed%', '%won%', '%lost%'] as $word) {
                    $s->whereRaw('LOWER(sales_stage) NOT LIKE ?', [$word]);
                }
            });
        });
    }

    /**
     * Planned tasks linked to a record, soonest first. A task marked PRIVATE is invisible to everyone but its owner (the calendar's own rule,
     * CalendarFeedService), so it is left out here too.
     *
     * @return list<AskRow>
     */
    private static function openTasks(AskCaller $caller, string $linkedType, int $id, string $page): array
    {
        if (! Schema::hasTable('task_management_calendar_events')) {
            return [];
        }
        $q = DB::table('task_management_calendar_events')->where('sub_institute_id', $caller->tenantId)->where('linked_type', $linkedType)->where('linked_id', $id)
            ->where('status', 'Planned')->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('visibility')->orWhere('visibility', '!=', 'PRIVATE')->orWhere('owner_id', $caller->userId));
        $out = [];
        foreach ($q->orderBy('start_at')->orderBy('id')->limit((int) config('ask.max_related_rows', 5))->get(['id', 'title', 'start_at']) as $task) {
            $out[] = new AskRow('task', (string) $task->id, (string) AskText::clip($task->title, 120), $page, ['title' => AskText::clip($task->title, 150), 'due' => self::day($task->start_at), 'status' => 'open']);
        }

        return $out;
    }

    // ---- summaries ----

    /**
     * Open and closed deals by stage and currency. Counts and sums only: no deal is named.
     *
     * @return list<array{stage: string, currency: ?string, deals: int, deals_with_an_amount: int, amount: ?float}> the amount adds up the deals that have one (null when none does)
     */
    public static function pipeline(AskCaller $caller): array
    {
        if (! self::hasTable('opportunity')) {
            return [];
        }
        $hasCurrency = in_array('currency', self::columns('crm_opportunities'), true);
        $q = self::base($caller, 'opportunity')->selectRaw('sales_stage, ' . ($hasCurrency ? 'currency' : 'NULL AS currency') . ', COUNT(*) AS deals, COUNT(amount) AS with_amount, SUM(amount) AS amount')
            ->groupBy('sales_stage')->orderByRaw('COUNT(*) DESC')->limit(30);
        if ($hasCurrency) {
            $q->groupBy('currency');
        }

        return $q->get()->map(fn ($r) => [
            'stage' => ($r->sales_stage ?? '') !== '' ? (string) $r->sales_stage : 'no stage', 'currency' => self::currency($r->currency ?? null),
            // A stage whose deals have no amount recorded has NO amount: null, never 0 (the first real run said "1 deal for 0" about a deal with no amount).
            'deals' => (int) $r->deals, 'deals_with_an_amount' => (int) $r->with_amount, 'amount' => (int) $r->with_amount > 0 ? round((float) $r->amount, 2) : null,
        ])->all();
    }

    /**
     * Leads by status, how many came from a signal, and where they came from (the source typed on the lead). Converted leads are counted apart (the lead
     * list hides them); the source breakdown counts every lead, converted ones included, at most SOURCE_CAP named sources plus the leads with no source recorded.
     *
     * @return array{open: int, converted: int, from_signals: ?int, by_status: array<string, int>, by_source: ?array<string, int>, sources_left_out: array{other_sources: int, leads_in_those: int}}|null null on a host without the leads table; by_source is null on a host without the lead_source column
     */
    public static function leads(AskCaller $caller): ?array
    {
        if (! self::hasTable('lead')) {
            return null;
        }
        $have = self::columns('crm_leads');
        $all = self::base($caller, 'lead');
        $converted = in_array('converted', $have, true) ? (int) (clone $all)->where('converted', true)->count() : 0;
        $open = self::base($caller, 'lead');
        if (in_array('converted', $have, true)) {
            $open->where('converted', false);
        }
        $byStatus = in_array('lead_status', $have, true)
            ? (clone $open)->selectRaw('lead_status, COUNT(*) AS n')->groupBy('lead_status')->orderByRaw('COUNT(*) DESC')->limit(20)->get()
                ->mapWithKeys(fn ($r) => [($r->lead_status ?? '') !== '' ? (string) $r->lead_status : 'no status' => (int) $r->n])->all()
            : [];
        $fromSignals = in_array('source_company_opportunity_id', $have, true) ? (int) self::base($caller, 'lead')->where('source_company_opportunity_id', '>', 0)->count() : null;

        [$bySource, $leftOut] = in_array('lead_source', $have, true) ? self::leadSources($caller) : [null, ['other_sources' => 0, 'leads_in_those' => 0]];

        return ['open' => (int) (clone $open)->count(), 'converted' => $converted, 'from_signals' => $fromSignals, 'by_status' => $byStatus, 'by_source' => $bySource, 'sources_left_out' => $leftOut];
    }

    /**
     * Where the caller's leads came from, by the source typed on the lead (a blank source is labelled, never dropped). Every lead counts, converted ones
     * included. At most SOURCE_CAP named sources are returned, biggest first; the rest are counted in the second part so the answer can say what it left out.
     *
     * @return array{0: array<string, int>, 1: array{other_sources: int, leads_in_those: int}} [source => leads, what the cap left out]
     */
    private static function leadSources(AskCaller $caller): array
    {
        $named = self::base($caller, 'lead')->whereRaw("TRIM(COALESCE(lead_source, '')) <> ''");
        $shown = (clone $named)->selectRaw('TRIM(lead_source) AS src, COUNT(*) AS n')->groupByRaw('TRIM(lead_source)')
            ->orderByRaw('COUNT(*) DESC')->orderByRaw('TRIM(lead_source) ASC')->limit(self::SOURCE_CAP)->get();
        $namedLeads = (int) (clone $named)->count();
        $namedSources = (int) ((clone $named)->selectRaw('COUNT(DISTINCT TRIM(lead_source)) AS n')->first()->n ?? 0);
        $blank = (int) self::base($caller, 'lead')->whereRaw("TRIM(COALESCE(lead_source, '')) = ''")->count();

        $counts = [];
        foreach ($shown as $r) {
            $counts[(string) $r->src] = (int) $r->n;
        }
        if ($blank > 0) {
            $counts[self::NO_SOURCE_LABEL] = ($counts[self::NO_SOURCE_LABEL] ?? 0) + $blank;
        }
        // Biggest first. The sort is stable (PHP 8), so equal counts keep the query's alphabetical order and the order is the same on every run.
        arsort($counts);

        return [$counts, [
            'other_sources' => max(0, $namedSources - $shown->count()),
            'leads_in_those' => max(0, $namedLeads - (int) $shown->sum('n')),
        ]];
    }

    // ---- row shapes ----

    /**
     * @param  iterable<object>  $records
     * @return list<AskRow>
     */
    private static function rows(AskCaller $caller, string $type, iterable $records): array
    {
        $records = is_array($records) ? $records : iterator_to_array($records, false);
        if ($records === []) {
            return [];
        }
        $owners = self::userNames($caller, array_map(fn ($r) => $r->assigned_to ?? null, $records));
        $orgs = in_array($type, ['contact', 'opportunity'], true)
            ? self::organisationNames($caller, array_map(fn ($r) => $r->organization_id ?? null, $records)) : [];

        $out = [];
        foreach ($records as $r) {
            $id = (string) $r->id;
            $owner = ($r->assigned_to ?? null) !== null ? ($owners[(int) $r->assigned_to] ?? null) : null;
            $out[] = match ($type) {
                'lead' => new AskRow('lead', $id, self::leadLabel($r), self::page('lead', $id), [
                    'lead_no' => $r->lead_no ?? null, 'company' => AskText::clip($r->company ?? null, 100), 'job_title' => AskText::clip($r->designation ?? null, 100),
                    'status' => $r->lead_status ?? null, 'lifecycle' => $r->lifecycle_status ?? null, 'source' => $r->lead_source ?? null, 'rating' => $r->rating ?? null,
                    'score' => $r->lead_score ?? null, 'owner' => $owner, 'follow_up_due' => self::day($r->follow_up_due_date ?? null),
                    'came_from_a_signal' => isset($r->source_company_opportunity_id) ? (int) $r->source_company_opportunity_id > 0 : null,
                    'city' => AskText::clip($r->city ?? null, 60), 'created' => self::day($r->created_at ?? null),
                    'email_on_file' => self::filled($r->email ?? null, $r->secondary_email ?? null), 'phone_on_file' => self::filled($r->phone ?? null, $r->mobile ?? null),
                    'not_shown_to_you' => 'email addresses, phone numbers and street addresses',
                ]),
                'contact' => new AskRow('contact', $id, self::personLabel($r), self::page('contact', $id), [
                    'contact_no' => $r->contact_no ?? null, 'job_title' => AskText::clip($r->title ?? null, 100), 'department' => AskText::clip($r->department ?? null, 100),
                    'organisation' => ($r->organization_id ?? null) !== null ? ($orgs[(int) $r->organization_id] ?? null) : null, 'source' => $r->lead_source ?? null,
                    'owner' => $owner, 'created' => self::day($r->created_at ?? null),
                    'email_on_file' => self::filled($r->email ?? null, $r->secondary_email ?? null), 'phone_on_file' => self::filled($r->phone ?? null, $r->mobile ?? null),
                    'not_shown_to_you' => 'email addresses, phone numbers and street addresses',
                ]),
                'organization' => new AskRow('organization', $id, (string) (AskText::clip($r->name ?? null, 120) ?? "Organisation #{$id}"), self::page('organization', $id), [
                    'account_no' => $r->account_no ?? null, 'type' => $r->account_type ?? null, 'industry' => $r->industry ?? null, 'rating' => $r->rating ?? null,
                    'city' => AskText::clip($r->billing_city ?? null, 60), 'state' => AskText::clip($r->billing_state ?? null, 60), 'country' => AskText::clip($r->billing_country ?? null, 60),
                    'employees' => $r->employees ?? null, 'website' => AskText::clip($r->website ?? null, 100), 'owner' => $owner, 'created' => self::day($r->created_at ?? null),
                    'not_shown_to_you' => 'email addresses, phone numbers and street addresses',
                ]),
                'opportunity' => new AskRow('opportunity', $id, (string) (AskText::clip($r->name ?? null, 120) ?? "Opportunity #{$id}"), self::page('opportunity', $id), [
                    'opportunity_no' => $r->opportunity_no ?? null, 'organisation' => ($r->organization_id ?? null) !== null ? ($orgs[(int) $r->organization_id] ?? null) : null,
                    'stage' => $r->sales_stage ?? null, 'amount' => isset($r->amount) ? round((float) $r->amount, 2) : null, 'currency' => self::currency($r->currency ?? null),
                    'expected_close' => self::day($r->closing_date ?? null), 'probability' => $r->probability ?? null, 'forecast' => $r->forecast_category ?? null,
                    'source' => $r->lead_source ?? null, 'owner' => $owner, 'created' => self::day($r->created_at ?? null),
                ]),
            };
        }

        return $out;
    }

    /** What a record is called (the label of its row). */
    public static function label(string $type, object $r): string
    {
        return match ($type) {
            'lead' => self::leadLabel($r),
            'contact' => self::personLabel($r),
            default => (string) (AskText::clip($r->name ?? null, 120) ?? ucfirst($type) . ' #' . $r->id),
        };
    }

    private static function leadLabel(object $r): string
    {
        $name = self::personLabel($r);
        $company = AskText::clip($r->company ?? null, 80);

        return $company !== null && $company !== '' ? "{$name} ({$company})" : $name;
    }

    private static function personLabel(object $r): string
    {
        $name = trim((string) AskText::clip(trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')), 120));

        return $name !== '' ? $name : 'Record #' . $r->id;
    }

    /** "RUP" is the CRM's legacy picklist value for rupees; the model is given the ISO code so it never echoes a code nobody uses. */
    private static function currency(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strcasecmp(trim($value), 'RUP') === 0 ? 'INR' : trim($value);
    }

    private static function day(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : '');

        return $text === '' || str_starts_with($text, '0000') ? null : substr($text, 0, 10);
    }

    private static function filled(mixed ...$values): bool
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string> the caller's organisation's people only: a pointer at someone of another organisation shows no name
     */
    private static function userNames(AskCaller $caller, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, fn ($v) => $v !== null)))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbluser')->where('sub_institute_id', $caller->tenantId)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $u) {
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            if ($name !== '') {
                $out[(int) $u->id] = $name;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string> names of the caller's organisation's organisations that are not deleted
     */
    private static function organisationNames(AskCaller $caller, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, fn ($v) => $v !== null)))));
        if ($ids === [] || ! self::hasTable('organization')) {
            return [];
        }

        return self::base($caller, 'organization')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }
}

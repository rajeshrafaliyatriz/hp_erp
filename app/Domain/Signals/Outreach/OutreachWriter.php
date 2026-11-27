<?php

namespace App\Domain\Signals\Outreach;

use App\Domain\OrgAccounts\OrgNormalizer;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\SignalContact;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The write side of the Outreach tab: companies and people added by hand, edits, removal, consent and owners. Always tenant-scoped.
 *
 * Every refusal comes back as `['ok' => false, 'http' => 422|404, 'code' => ..., 'message' => plain words, ...]` and writes nothing; every success as `['ok' => true, ...]`.
 * Values are held to the same checks as the contacts the research finds (SignalContact::validEmail / normalisePhone), and a person is de-duplicated the way
 * SignalContactStore::save does it (email, phone, LinkedIn address, then name), so adding by hand and finding on the web never make two of the same person.
 * Nothing here sends anything or touches the CRM.
 */
final class OutreachWriter
{
    private const PERSON_TEXT = ['full_name' => 191, 'designation' => 191, 'department' => 100, 'contact_role' => 40, 'linkedin_url' => 490, 'description' => 2000, 'location' => 191, 'website' => 490];

    // ── companies ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $in @return array{ok: true, id: int}|array<string, mixed> */
    public function addCompany(int $tenant, int $actor, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return $this->fail(422, 'name_required', 'Name the company.');
        }
        $normalized = StructuredAi::normalise($name);
        $existing = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('normalized_name', $normalized)->whereNull('deleted_at')->first(['id', 'name']);
        if ($existing) {
            return $this->fail(422, 'exists', "{$existing->name} is already here. Open it instead of adding it again.", ['company_id' => (int) $existing->id]);
        }
        [$email, $phone, $problem] = $this->contactFacts($in['email'] ?? null, $in['phone'] ?? null);
        if ($problem) {
            return $problem;
        }
        $website = $this->clean($in['website'] ?? null, 490);
        $assigned = $this->assignee($tenant, $in['assigned_to'] ?? null);
        if ($assigned === false) {
            return $this->fail(422, 'bad_owner', 'That person is not one of your users.');
        }
        $id = (int) DB::table('g2g_companies')->insertGetId([
            'sub_institute_id' => $tenant, 'name' => mb_substr($name, 0, 191), 'normalized_name' => mb_substr($normalized, 0, 191), 'website' => $website,
            'domain' => $website ? (OrgNormalizer::domain($website) ?: null) : null, 'industry' => $this->clean($in['industry'] ?? null, 191), 'location' => $this->clean($in['location'] ?? null, 191),
            'type' => $this->clean($in['type'] ?? null, 30), 'state' => $this->clean($in['state'] ?? null, 100), 'phone' => $phone, 'email' => $email,
            'description' => $this->clean($in['description'] ?? null, 2000), 'source' => 'manual', 'assigned_to' => $assigned, 'created_by' => $actor,
            'first_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * What a person may change: a company added by hand, everything; a company signals found, only the facts nobody else sets (phone, email, note, owner). Its name, website and
     * place belong to the signals that found it and are left alone, silently, so one form serves both.
     *
     * @param array<string, mixed> $in @return array{ok: true}|array<string, mixed>
     */
    public function updateCompany(int $tenant, int $id, array $in): array
    {
        $c = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $id)->whereNull('deleted_at')->first(['id', 'source', 'normalized_name']);
        if (! $c) {
            return $this->fail(404, 'not_found', 'Company not found.');
        }
        $set = [];
        if (array_key_exists('phone', $in) || array_key_exists('email', $in)) {
            [$email, $phone, $problem] = $this->contactFacts($in['email'] ?? null, $in['phone'] ?? null);
            if ($problem) {
                return $problem;
            }
            if (array_key_exists('email', $in)) {
                $set['email'] = $email;
            }
            if (array_key_exists('phone', $in)) {
                $set['phone'] = $phone;
            }
        }
        if (array_key_exists('description', $in)) {
            $set['description'] = $this->clean($in['description'], 2000);
        }
        if (array_key_exists('assigned_to', $in)) {
            $assigned = $this->assignee($tenant, $in['assigned_to']);
            if ($assigned === false) {
                return $this->fail(422, 'bad_owner', 'That person is not one of your users.');
            }
            $set['assigned_to'] = $assigned;
        }
        if ($c->source === 'manual') {
            if (array_key_exists('name', $in)) {
                $name = trim((string) $in['name']);
                if ($name === '') {
                    return $this->fail(422, 'name_required', 'Name the company.');
                }
                $normalized = mb_substr(StructuredAi::normalise($name), 0, 191);
                $clash = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('normalized_name', $normalized)->where('id', '<>', $id)->whereNull('deleted_at')->value('id');
                if ($clash) {
                    return $this->fail(422, 'exists', 'Another company already has that name.', ['company_id' => (int) $clash]);
                }
                $set['name'] = mb_substr($name, 0, 191);
                $set['normalized_name'] = $normalized;
            }
            foreach (['industry' => 191, 'location' => 191, 'type' => 30, 'state' => 100] as $field => $max) {
                if (array_key_exists($field, $in)) {
                    $set[$field] = $this->clean($in[$field], $max);
                }
            }
            if (array_key_exists('website', $in)) {
                $set['website'] = $this->clean($in['website'], 490);
                $set['domain'] = $set['website'] ? (OrgNormalizer::domain($set['website']) ?: null) : null;
            }
        }
        if ($set !== []) {
            DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $id)->update($set + ['updated_at' => now()]);
        }

        return ['ok' => true];
    }

    /** Only a company added by hand that no signal has found since, and that has not become a lead, can be removed; its people go with it. @return array{ok: true}|array<string, mixed> */
    public function deleteCompany(int $tenant, int $id): array
    {
        $c = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $id)->whereNull('deleted_at')->first(['id', 'source', 'lead_id']);
        if (! $c) {
            return $this->fail(404, 'not_found', 'Company not found.');
        }
        if ($c->source !== 'manual') {
            return $this->fail(422, 'found_company', 'A company that signals found cannot be removed here, because those signals point at it. Mark its people do not contact instead.');
        }
        if (DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('company_id', $id)->exists()) {
            return $this->fail(422, 'has_signals', 'A signal has since found this company, so it cannot be removed here.');
        }
        if ($c->lead_id) {
            return $this->fail(422, 'has_lead', 'This company became a lead, so it cannot be removed here.');
        }
        DB::transaction(function () use ($tenant, $id) {
            DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('company_id', $id)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
            DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return ['ok' => true];
    }

    // ── people ───────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $in @return array{ok: true, id: int}|array<string, mixed> */
    public function addPerson(int $tenant, int $actor, array $in): array
    {
        $companyId = (int) ($in['company_id'] ?? 0);
        if (! DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $companyId)->whereNull('deleted_at')->exists()) {
            return $this->fail(422, 'no_company', 'Choose one of your own companies.');
        }
        $built = $this->personFacts($in, true);
        if (isset($built['ok'])) {
            return $built;
        }
        $assigned = $this->assignee($tenant, $in['assigned_to'] ?? null);
        if ($assigned === false) {
            return $this->fail(422, 'bad_owner', 'That person is not one of your users.');
        }
        $row = $built['row'];
        if ($row['full_name'] === null && $row['email'] === null && $row['phone'] === null && $row['mobile'] === null && $row['linkedin_url'] === null) {
            return $this->fail(422, 'nothing_to_go_on', 'Give a name, an email address or a phone number.');
        }
        if ($dupe = $this->duplicate($tenant, $companyId, $row, null)) {
            return $this->fail(422, 'duplicate', "{$dupe['label']} is already listed for this company.", ['person_id' => (int) $dupe['id']]);
        }
        $id = DB::transaction(function () use ($tenant, $actor, $companyId, $row, $assigned, $in) {
            $primary = (bool) ($in['is_primary'] ?? false);
            if ($primary) {
                DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('company_id', $companyId)->update(['is_primary' => 0]);
            }

            return (int) DB::table('g2g_signal_contacts')->insertGetId($row + [
                'sub_institute_id' => $tenant, 'company_id' => $companyId, 'source' => 'manual', 'verification_status' => 'unverified', 'is_primary' => $primary ? 1 : 0,
                'consent_status' => 'unknown', 'assigned_to' => $assigned, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Any person's details can be corrected. When an email or phone is changed its source address is cleared (the page it was found on no longer says that).
     *
     * @param array<string, mixed> $in @return array{ok: true}|array<string, mixed>
     */
    public function updatePerson(int $tenant, int $id, array $in): array
    {
        $p = $this->load($tenant, $id);
        if (! $p) {
            return $this->fail(404, 'not_found', 'Person not found.');
        }
        $built = $this->personFacts($in, false);
        if (isset($built['ok'])) {
            return $built;
        }
        $set = $built['row'];
        foreach (['email', 'phone'] as $field) {
            if (array_key_exists($field, $set) && $set[$field] !== $p->{$field}) {
                $set["{$field}_source_url"] = null;
            }
        }
        if (array_key_exists('assigned_to', $in)) {
            $assigned = $this->assignee($tenant, $in['assigned_to']);
            if ($assigned === false) {
                return $this->fail(422, 'bad_owner', 'That person is not one of your users.');
            }
            $set['assigned_to'] = $assigned;
        }
        $merged = array_merge((array) $p, $set);
        if ($merged['full_name'] === null && $merged['email'] === null && $merged['phone'] === null && $merged['mobile'] === null && $merged['linkedin_url'] === null) {
            return $this->fail(422, 'nothing_to_go_on', 'A person needs a name, an email address or a phone number.');
        }
        if ($dupe = $this->duplicate($tenant, (int) $p->company_id, $merged, $id)) {
            return $this->fail(422, 'duplicate', "{$dupe['label']} is already listed for this company.", ['person_id' => (int) $dupe['id']]);
        }
        DB::transaction(function () use ($tenant, $id, $p, $set, $in) {
            if (array_key_exists('is_primary', $in) && (bool) $in['is_primary']) {
                DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('company_id', $p->company_id)->where('id', '<>', $id)->update(['is_primary' => 0]);
                $set['is_primary'] = 1;
            } elseif (array_key_exists('is_primary', $in)) {
                $set['is_primary'] = 0;
            }
            if ($set !== []) {
                DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $id)->update($set + ['updated_at' => now()]);
            }
        });

        return ['ok' => true];
    }

    /**
     * Removes the person from the lists (a soft delete). The research that found them will not bring them back: the contact store still sees the row when it de-duplicates.
     *
     * @return array{ok: true}|array<string, mixed>
     */
    public function deletePerson(int $tenant, int $id): array
    {
        $p = $this->load($tenant, $id);
        if (! $p) {
            return $this->fail(404, 'not_found', 'Person not found.');
        }
        DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return ['ok' => true];
    }

    /**
     * What the person has said about being contacted. Opted out or suppressed rules them out completely (and stamps when and why); any other answer lifts that. The two flags
     * rule out one channel each. The caller carries the answer to the send engine (SignalContactBridge::syncConsent): this only records it.
     *
     * @param array<string, mixed> $in @return array{ok: true}|array<string, mixed>
     */
    public function setConsent(int $tenant, int $id, array $in): array
    {
        $p = $this->load($tenant, $id);
        if (! $p) {
            return $this->fail(404, 'not_found', 'Person not found.');
        }
        $set = [];
        if (isset($in['consent_status'])) {
            $status = (string) $in['consent_status'];
            if (! in_array($status, OutreachReach::CONSENT, true)) {
                return $this->fail(422, 'bad_consent', 'Choose: no answer yet, agreed, opted out or suppressed.');
            }
            $set['consent_status'] = $status;
            if (in_array($status, OutreachReach::CONSENT_BLOCKING, true)) {
                $set['suppressed_at'] = $p->suppressed_at ?: now();
                $set['suppressed_reason'] = $this->clean($in['reason'] ?? null, 191) ?: ($status === 'opted_out' ? 'opted out' : 'suppressed');
            } else {
                $set['suppressed_at'] = null;
                $set['suppressed_reason'] = null;
            }
        }
        foreach (['email_opt_out', 'do_not_call'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $set[$flag] = (bool) $in[$flag] ? 1 : 0;
            }
        }
        if ($set !== []) {
            DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $id)->update($set + ['updated_at' => now()]);
        }

        return ['ok' => true];
    }

    /**
     * @param list<int> $companyIds
     * @param list<int> $personIds
     * @return array{ok: true, companies: int, people: int}|array<string, mixed>
     */
    public function assign(int $tenant, array $companyIds, array $personIds, mixed $owner): array
    {
        $assigned = $this->assignee($tenant, $owner);
        if ($assigned === false) {
            return $this->fail(422, 'bad_owner', 'That person is not one of your users.');
        }
        $companies = $companyIds === [] ? 0 : DB::table('g2g_companies')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('id', array_map('intval', $companyIds))
            ->update(['assigned_to' => $assigned, 'updated_at' => now()]);
        $people = $personIds === [] ? 0 : DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('id', array_map('intval', $personIds))
            ->update(['assigned_to' => $assigned, 'updated_at' => now()]);

        return ['ok' => true, 'companies' => (int) $companies, 'people' => (int) $people];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function load(int $tenant, int $id): ?object
    {
        return DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $id)->whereNull('deleted_at')->first();
    }

    /**
     * The columns of a person from the request. Only keys that were sent are included when updating, so a form that sends one field changes one field.
     *
     * @param array<string, mixed> $in
     * @return array{row: array<string, mixed>}|array<string, mixed>
     */
    private function personFacts(array $in, bool $creating): array
    {
        $row = [];
        foreach (self::PERSON_TEXT as $field => $max) {
            if ($creating || array_key_exists($field, $in)) {
                $row[$field] = $this->clean($in[$field] ?? null, $max);
            }
        }
        foreach (['email', 'secondary_email'] as $field) {
            if ($creating || array_key_exists($field, $in)) {
                $v = $this->clean($in[$field] ?? null, 191);
                if ($v !== null && ! SignalContact::validEmail($v)) {
                    return $this->fail(422, 'bad_email', 'This does not look like a usable email address.', ['field' => $field]);
                }
                $row[$field] = $v !== null ? strtolower($v) : null;
            }
        }
        foreach (['phone', 'mobile'] as $field) {
            if ($creating || array_key_exists($field, $in)) {
                $v = $this->clean($in[$field] ?? null, 40);
                $n = $v !== null ? SignalContact::normalisePhone($v) : null;
                if ($v !== null && $n === null) {
                    return $this->fail(422, 'bad_phone', 'This does not look like a usable phone number. Use a 10-digit mobile or a number starting with +.', ['field' => $field]);
                }
                $row[$field] = $n;
            }
        }

        return ['row' => $row];
    }

    /** The same person already listed for the company, by the contact store's own rules: email, phone, LinkedIn address, then name. @param array<string, mixed> $row @return ?array{id: int, label: string} */
    private function duplicate(int $tenant, int $companyId, array $row, ?int $ignoreId): ?array
    {
        $q = DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('company_id', $companyId)->whereNull('deleted_at');
        if ($ignoreId !== null) {
            $q->where('id', '<>', $ignoreId);
        }
        foreach ($q->get() as $e) {
            $same = (($row['email'] ?? null) !== null && strtolower((string) $e->email) === $row['email'])
                || (($row['phone'] ?? null) !== null && (string) $e->phone === $row['phone'])
                || (($row['linkedin_url'] ?? null) !== null && strtolower((string) $e->linkedin_url) === strtolower((string) $row['linkedin_url']))
                || (($row['full_name'] ?? null) !== null && strtolower((string) $e->full_name) === strtolower((string) $row['full_name']));
            if ($same) {
                return ['id' => (int) $e->id, 'label' => $e->full_name ?: ($e->email ?: ($e->phone ?: 'This contact'))];
            }
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?array<string, mixed>} the checked email, the normalised phone, and a refusal when one is not usable */
    private function contactFacts(mixed $email, mixed $phone): array
    {
        $e = $this->clean($email, 191);
        if ($e !== null && ! SignalContact::validEmail($e)) {
            return [null, null, $this->fail(422, 'bad_email', 'This does not look like a usable email address.', ['field' => 'email'])];
        }
        $p = $this->clean($phone, 40);
        $n = $p !== null ? SignalContact::normalisePhone($p) : null;
        if ($p !== null && $n === null) {
            return [null, null, $this->fail(422, 'bad_phone', 'This does not look like a usable phone number. Use a 10-digit mobile or a number starting with +.', ['field' => 'phone'])];
        }

        return [$e !== null ? strtolower($e) : null, $n, null];
    }

    /** A user of this organisation, null for "nobody", false for a user that is not this organisation's. */
    private function assignee(int $tenant, mixed $id): int|null|false
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }
        if (! Schema::hasTable('tbluser')) {
            return (int) $id;
        }

        return DB::table('tbluser')->where('sub_institute_id', $tenant)->where('id', (int) $id)->exists() ? (int) $id : false;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function fail(int $http, string $code, string $message, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message] + $extra;
    }
}

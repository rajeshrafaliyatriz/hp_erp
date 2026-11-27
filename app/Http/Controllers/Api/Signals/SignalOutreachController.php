<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Outreach\OutreachDirectory;
use App\Domain\Signals\Outreach\OutreachLeadConverter;
use App\Domain\Signals\Outreach\OutreachWriter;
use App\Domain\Signals\Outreach\SignalContactBridge;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\EnrichSignalContactsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The Outreach tab of Market Signals (27 Nov 2026): the companies that signals found (or a person added by hand), the people at them, and writing to those people.
 *
 * Tenant and user come from the token only; another organisation's record is answered exactly like a missing one (404). The reads are in OutreachDirectory, the writes in
 * OutreachWriter, and the road into the send engine in SignalContactBridge. Nothing here sends anything: "write a message" saves DRAFTS, and submitting, approving and sending stay
 * on the GTM Outreach endpoints behind their own gates. Contacts found on the public web are never verified, and the responses say so (`verification_status`).
 */
class SignalOutreachController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(
        private readonly OutreachDirectory $directory,
        private readonly OutreachWriter $writer,
        private readonly SignalContactBridge $bridge,
        private readonly OutreachLeadConverter $leads,
    ) {
    }

    // ── reads ────────────────────────────────────────────────────────────────────

    public function companies(Request $request): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $r = $this->directory->companies($identity['sub_institute_id'], (int) $identity['user_id'], $this->filters($request));

        return response()->json(['status' => 1] + $r);
    }

    public function company(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $row = $this->directory->company($identity['sub_institute_id'], $id);

        return $row ? response()->json(['status' => 1, 'data' => $row]) : $this->notFound('Company not found.');
    }

    public function people(Request $request): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $r = $this->directory->people($identity['sub_institute_id'], (int) $identity['user_id'], $this->filters($request));

        return response()->json(['status' => 1] + $r);
    }

    /** One person, by id (a shared link to a person opens even when they are not on the page of the list a viewer has). */
    public function person(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $row = $this->directory->person($identity['sub_institute_id'], $id);

        return $row ? response()->json(['status' => 1, 'data' => $row]) : $this->notFound('Person not found.');
    }

    public function personMessages(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        if (! $this->directory->person($identity['sub_institute_id'], $id)) {
            return $this->notFound('Person not found.');
        }

        return response()->json(['status' => 1, 'data' => $this->directory->messages($identity['sub_institute_id'], $id)]);
    }

    // ── companies: add, change, remove ───────────────────────────────────────────

    public function storeCompany(Request $request): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), $this->companyRules(true));
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $made = $this->writer->addCompany($identity['sub_institute_id'], (int) $identity['user_id'], $v->validated());
        if (! $made['ok']) {
            return $this->refusal($made);
        }

        return response()->json(['status' => 1, 'data' => $this->directory->company($identity['sub_institute_id'], $made['id'])], 201);
    }

    public function updateCompany(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), $this->companyRules(false));
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $done = $this->writer->updateCompany($identity['sub_institute_id'], $id, $v->validated());

        return $done['ok'] ? response()->json(['status' => 1, 'data' => $this->directory->company($identity['sub_institute_id'], $id)]) : $this->refusal($done);
    }

    public function destroyCompany(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $done = $this->writer->deleteCompany($identity['sub_institute_id'], $id);

        return $done['ok'] ? response()->json(['status' => 1]) : $this->refusal($done);
    }

    // ── people: add, change, remove, consent ─────────────────────────────────────

    public function storePerson(Request $request): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), ['company_id' => 'required|integer|min:1'] + $this->personRules());
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $made = $this->writer->addPerson($identity['sub_institute_id'], (int) $identity['user_id'], $v->validated());
        if (! $made['ok']) {
            return $this->refusal($made);
        }

        return response()->json(['status' => 1, 'data' => $this->directory->person($identity['sub_institute_id'], $made['id'])], 201);
    }

    public function updatePerson(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), $this->personRules());
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $done = $this->writer->updatePerson($identity['sub_institute_id'], $id, $v->validated());

        return $done['ok'] ? response()->json(['status' => 1, 'data' => $this->directory->person($identity['sub_institute_id'], $id)]) : $this->refusal($done);
    }

    public function destroyPerson(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $done = $this->writer->deletePerson($identity['sub_institute_id'], $id);

        return $done['ok'] ? response()->json(['status' => 1]) : $this->refusal($done);
    }

    public function consent(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), ['consent_status' => 'sometimes|string|max:20', 'email_opt_out' => 'sometimes|boolean', 'do_not_call' => 'sometimes|boolean', 'reason' => 'sometimes|nullable|string|max:191']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $t = $identity['sub_institute_id'];
        $done = $this->writer->setConsent($t, $id, $v->validated());
        if (! $done['ok']) {
            return $this->refusal($done);
        }
        // What the person said reaches the send engine too, so the Outreach screen and the mailer refuse them as well.
        $this->bridge->syncConsent($t, $id);

        return response()->json(['status' => 1, 'data' => $this->directory->person($t, $id)]);
    }

    public function assign(Request $request): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), [
            'company_ids' => 'sometimes|array|max:200', 'company_ids.*' => 'integer', 'person_ids' => 'sometimes|array|max:200', 'person_ids.*' => 'integer', 'assigned_to' => 'present|nullable|integer',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $done = $this->writer->assign($identity['sub_institute_id'], $v->validated()['company_ids'] ?? [], $v->validated()['person_ids'] ?? [], $v->validated()['assigned_to']);

        return $done['ok'] ? response()->json(['status' => 1, 'data' => ['companies' => $done['companies'], 'people' => $done['people']]]) : $this->refusal($done);
    }

    // ── actions ──────────────────────────────────────────────────────────────────

    /** Looks for public contacts for the company's latest signal now (queued). Information only: nothing about the signal or the company changes. */
    public function findContacts(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $t = $identity['sub_institute_id'];
        if (! $this->directory->company($t, $id)) {
            return $this->notFound('Company not found.');
        }
        $signal = CompanyOpportunity::where('sub_institute_id', $t)->where('company_id', $id)->orderByDesc('id')->first(['id']);
        if (! $signal) {
            return response()->json(['status' => 0, 'code' => 'no_signal', 'message' => 'This company was added by hand, so there is no signal to search from. Add the person yourself.'], 422);
        }
        if (! EnrichSignalContactsJob::isAvailable()) {
            return response()->json(['status' => 0, 'code' => 'not_set_up', 'message' => 'Contact search is not set up: no web-search provider key is configured.'], 422);
        }
        EnrichSignalContactsJob::dispatchFor($t, (int) $signal->id, true);

        return response()->json(['status' => 1, 'message' => 'Searching public sources for contacts. Check back in a minute.']);
    }

    public function convertToLead(Request $request, int $id): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), ['person_id' => 'sometimes|nullable|integer|min:1']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $r = $this->leads->convert($identity['sub_institute_id'], (int) $identity['user_id'], $id, $v->validated()['person_id'] ?? null);
        if (! $r['ok']) {
            return $this->refusal($r);
        }

        return response()->json(['status' => 1, 'data' => ['lead_id' => $r['lead_id'], 'created' => $r['created']]], $r['created'] ? 201 : 200);
    }

    /**
     * One DRAFT per person, each refused person named with the reason. Always answers 200/201 with the per-person results (a screen needs them even when every person was refused).
     * Nothing is submitted, approved or sent.
     */
    public function messageDrafts(Request $request): JsonResponse
    {
        [$identity, $stop] = $this->start($request);
        if ($stop) {
            return $stop;
        }
        $v = Validator::make($request->all(), ['person_ids' => 'required|array|min:1|max:50', 'person_ids.*' => 'integer|min:1', 'subject' => 'required|string|max:255', 'body' => 'required|string|min:20|max:20000']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $d = $v->validated();
        $results = $this->bridge->write($identity['sub_institute_id'], (int) $identity['user_id'], $d['person_ids'], $d['subject'], $d['body']);
        $drafted = count(array_filter($results, fn ($r) => $r['ok']));

        return response()->json(['status' => 1, 'data' => ['results' => $results, 'drafted' => $drafted, 'refused' => count($results) - $drafted]], $drafted > 0 ? 201 : 200);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /** @return array{0: ?array<string, mixed>, 1: ?JsonResponse} the signed-in identity, or the answer to give instead */
    private function start(Request $request): array
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return [null, $identity];
        }
        if (! OutreachDirectory::ready()) {
            return [null, response()->json(['status' => 0, 'code' => 'not_set_up', 'message' => 'The Outreach tab is not set up on this server yet (its database change has not been applied).'], 503)];
        }

        return [$identity, null];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'search' => (string) $request->query('search', ''),
            'reach' => (string) $request->query('reach', 'all'),
            'source' => (string) $request->query('source', 'all'),
            'mine' => $request->boolean('mine'),
            'company_id' => $request->query('company_id'),
            'sort' => (string) $request->query('sort', 'name'),
            'dir' => $request->query('dir') === 'desc' ? 'desc' : 'asc',
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
        ];
    }

    /** @return array<string, string> */
    private function companyRules(bool $creating): array
    {
        $rules = [
            'website' => 'sometimes|nullable|string|max:490', 'industry' => 'sometimes|nullable|string|max:191', 'location' => 'sometimes|nullable|string|max:191', 'type' => 'sometimes|nullable|string|max:30',
            'state' => 'sometimes|nullable|string|max:100', 'phone' => 'sometimes|nullable|string|max:40', 'email' => 'sometimes|nullable|string|max:191',
            'description' => 'sometimes|nullable|string|max:2000', 'assigned_to' => 'sometimes|nullable|integer',
        ];

        return ($creating ? ['name' => 'required|string|max:191'] : ['name' => 'sometimes|string|max:191']) + $rules;
    }

    /** @return array<string, string> */
    private function personRules(): array
    {
        $text = fn (int $max) => "sometimes|nullable|string|max:{$max}";

        return [
            'full_name' => $text(191), 'designation' => $text(191), 'department' => $text(100), 'contact_role' => $text(40), 'email' => $text(191), 'secondary_email' => $text(191),
            'phone' => $text(40), 'mobile' => $text(40), 'linkedin_url' => $text(490), 'description' => $text(2000), 'is_primary' => 'sometimes|boolean', 'assigned_to' => 'sometimes|nullable|integer',
        ];
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], 404);
    }

    /** @param array<string, mixed> $r a refusal from the writer or the converter */
    private function refusal(array $r): JsonResponse
    {
        return response()->json(['status' => 0] + array_diff_key($r, ['ok' => 1, 'http' => 1]), $r['http'] ?? 422);
    }

    private function invalid($validator): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }
}

<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Contacts at a GTM account, and the account timeline (notes / calls / meetings).
 *
 * Contacts are entered or imported by people - nothing here invents a person. A contact
 * is only reachable through an account of the caller's own organisation.
 */
class ContactController extends Controller
{
    use ResolvesApiIdentity;

    private const ROLES = ['champion', 'economic_buyer', 'decision_maker', 'influencer', 'user', 'blocker'];
    private const STATUSES = ['active', 'bounced', 'unsubscribed', 'do_not_contact'];
    private const ACTIVITY_TYPES = ['note', 'call', 'meeting', 'email', 'linkedin', 'task'];

    public function store(Request $request, int $accountId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        if (! GtmAccount::where('sub_institute_id', $tenant)->whereKey($accountId)->exists()) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }

        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();

        if (! empty($data['email']) && GtmContact::where('sub_institute_id', $tenant)->where('account_id', $accountId)->where('email', $data['email'])->exists()) {
            return response()->json(['status' => 0, 'message' => 'This contact email already exists on the account.'], 422);
        }

        $contact = GtmContact::create($data + [
            'sub_institute_id' => $tenant, 'account_id' => $accountId, 'source' => 'manual', 'created_by' => $identity['user_id'],
        ]);
        GtmAudit::record('gtm.contact.created', $tenant, 'gtm_contacts', $contact->id, $identity['user_id'], ['account_id' => $accountId]);

        return response()->json(['status' => 1, 'data' => ['contact' => $contact]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $contact = GtmContact::where('sub_institute_id', $tenant)->find($id);
        if (! $contact) {
            return response()->json(['status' => 0, 'message' => 'Contact not found'], 404);
        }

        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $contact->fill($v->validated())->save();
        GtmAudit::record('gtm.contact.updated', $tenant, 'gtm_contacts', $contact->id, $identity['user_id'], ['changed' => array_keys($v->validated())]);

        return response()->json(['status' => 1, 'data' => ['contact' => $contact->fresh()]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $contact = GtmContact::where('sub_institute_id', $tenant)->find($id);
        if (! $contact) {
            return response()->json(['status' => 0, 'message' => 'Contact not found'], 404);
        }
        $contact->delete();
        GtmAudit::record('gtm.contact.deleted', $tenant, 'gtm_contacts', $id, $identity['user_id'], ['account_id' => $contact->account_id]);

        return response()->json(['status' => 1, 'message' => 'Contact removed']);
    }

    /** Log a human touch (note, call, meeting...) against an account. */
    public function logActivity(Request $request, int $accountId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        if (! GtmAccount::where('sub_institute_id', $tenant)->whereKey($accountId)->exists()) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'type' => 'required|in:'.implode(',', self::ACTIVITY_TYPES),
            'direction' => 'nullable|in:inbound,outbound',
            'subject' => 'required|string|max:255',
            'body' => 'nullable|string|max:20000',
            'contact_id' => 'nullable|integer',
            'occurred_at' => 'nullable|date|before_or_equal:now',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();

        if (! empty($data['contact_id']) && ! GtmContact::where('sub_institute_id', $tenant)->where('account_id', $accountId)->whereKey($data['contact_id'])->exists()) {
            return response()->json(['status' => 0, 'message' => 'Contact does not belong to this account.'], 422);
        }

        $activity = GtmActivity::create($data + [
            'sub_institute_id' => $tenant, 'account_id' => $accountId, 'user_id' => $identity['user_id'],
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);
        GtmAudit::record('gtm.activity.logged', $tenant, 'gtm_activities', $activity->id, $identity['user_id'], ['account_id' => $accountId, 'type' => $data['type']]);

        return response()->json(['status' => 1, 'data' => ['activity' => $activity]], 201);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => "$req|string|max:191",
            'title' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'linkedin_url' => 'nullable|url|max:255',
            'role_in_deal' => 'nullable|in:'.implode(',', self::ROLES),
            'status' => 'sometimes|in:'.implode(',', self::STATUSES),
            'notes' => 'nullable|string|max:4000',
        ];
    }
}

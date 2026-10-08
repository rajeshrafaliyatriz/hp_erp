<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\CalendarShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Calendar sharing: outgoing grants (whom I've shared MY calendar with) and
 * the incoming feed list (whose calendars I may overlay) are deliberately
 * two different reads — see CalendarShareService's docblock.
 *
 * Sharing your own calendar needs no privileged ability: there is no id to
 * tamper with (you can only ever grant access to YOUR OWN calendar), the
 * same reasoning the account/preferences routes document for why a
 * self-only write needs no role guard. There is no org-wide default here to
 * gate at all - see the NOT BUILT note below for why one was never added.
 */
class CalendarShareController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly CalendarShareService $shares)
    {
    }

    /** Whom I've granted access to. */
    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = $this->shares->outgoing($context['user_id'], $context['sub_institute_id'])->map(fn ($row) => [
            'id' => (string) $row->id,
            'viewer_user_id' => (string) $row->viewer_user_id,
            'viewer_name' => $row->viewer_user_id == CalendarShareService::EVERYONE ? 'Everyone' : ($row->viewer_name ?: 'Unknown'),
            'can_edit' => (bool) $row->can_edit,
            'color' => $row->color,
        ]);

        return $this->ok('Calendar shares retrieved successfully.', ['shares' => $rows]);
    }

    public function store(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            // 0 = everyone, matching CalendarShareService::EVERYONE.
            'viewer_user_id' => 'required|integer|min:0',
            'can_edit' => 'nullable|boolean',
            // Same shape as task_management_statuses/priorities.color - a
            // free-form string the picker writes, not a validated hex format.
            'color' => 'nullable|string|max:30',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $this->shares->share(
            $context['user_id'], (int) $request->input('viewer_user_id'), $request->boolean('can_edit'),
            $context['sub_institute_id'], $context['user_id'], $request->input('color')
        );

        return $this->ok('Calendar shared.', [], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return $this->shares->unshare($context['user_id'], $id)
            ? $this->ok('Share removed.')
            : $this->fail('Share not found.', 404);
    }

    /** Whose calendars I may overlay — feeds, for the toggle panel. */
    public function feeds(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $feeds = $this->shares->incoming($context['user_id'], $context['sub_institute_id']);

        return $this->ok('Calendar feeds retrieved successfully.', ['feeds' => $feeds]);
    }

    /*
     * NOT BUILT: an org-wide "default sharing policy" admin setting.
     *
     * The plan called for one, assuming UserPreferences was a generic
     * per-tenant key/value store it could piggyback on - it is not: it is
     * keyed by USER, against a hardcoded allow-list (see its own docblock,
     * "this one is keyed by user"). There is no existing tenant-wide
     * settings store to hang one boolean off of, and standing up a new
     * table/mechanism for a single default is disproportionate here. Every
     * share in this phase is an explicit, per-person grant regardless of any
     * tenant default - that already works without this setting; it is a cut,
     * not a half-finished feature.
     */
}

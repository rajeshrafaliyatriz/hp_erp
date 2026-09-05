<?php

namespace App\Http\Controllers\Api\Mobility;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Mobility\MobilityPromotion;
use App\Http\Controllers\Api\Mobility\Concerns\ResolvesMobilityContext;

class MobilityPromotionController extends Controller
{
    use ResolvesMobilityContext;

    public function __construct(private EventRecorder $events)
    {
    }

    public function index(Request $request)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];
        $paging = $this->mobilityPaging($request, 10);

        $query = MobilityPromotion::where('sub_institute_id', $subInstituteId);

        if ($status = $request->input('status')) {
            if (strtolower($status) !== 'all') {
                $query->where('status', $status);
            }
        }

        $total = $query->count();
        $items = $query->orderBy('effective_date', 'desc')
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $userIds = $items->pluck('user_id')->all();
        $directory = $this->mobilityDirectory($subInstituteId, $userIds);

        foreach ($items as $item) {
            $item->employee = $directory[$item->user_id] ?? null;
        }

        return $this->mobilityResponse($items, 'Success', 200, [
            'total' => $total,
            'page' => $paging['page'],
            'per_page' => $paging['per_page'],
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'current_grade' => 'nullable|string|max:50',
            'proposed_grade' => 'required|string|max:50',
            'current_designation' => 'nullable|string|max:191',
            'proposed_designation' => 'required|string|max:191',
            'effective_date' => 'required|date',
            'status' => 'required|string|in:Pending,Approved,Completed,Cancelled',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->mobilityError($validator->errors()->first(), 422);
        }

        /*
         * The subject must belong to the caller's organisation.
         *
         * `required|integer` proved only that a number was sent. The tenant guard
         * lived solely in the WHERE clause of the two profile writes, so a foreign
         * user_id produced a promotion row that updated nobody - and then inserted
         * an org_designation row into the CALLER's tenant for a person who is not
         * in it.
         */
        $subjectInTenant = DB::table('tbluser')
            ->where('id', (int) $request->input('user_id'))
            ->where('sub_institute_id', $subInstituteId)
            ->exists();

        if (!$subjectInTenant) {
            return $this->mobilityError('Employee not found.', 404);
        }

        // One transaction: the promotion row, tbluser and org_designation are three
        // tables describing one fact. Committing some of them is worse than none.
        $promo = DB::transaction(function () use ($validator, $subInstituteId, $actorId) {
            $promo = MobilityPromotion::create(array_merge($validator->validated(), [
                'sub_institute_id' => $subInstituteId,
                'created_by' => $actorId,
            ]));

            if ($promo->status === 'Completed') {
                $this->completePromotionInProfile($promo);
            }

            return $promo;
        });

        return $this->mobilityResponse($promo, 'Promotion recorded successfully', 201);
    }

    public function update(Request $request, $id)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $promo = MobilityPromotion::where('sub_institute_id', $context['sub_institute_id'])
            ->find($id);

        if (!$promo) {
            return $this->mobilityError('Promotion record not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:Pending,Approved,Completed,Cancelled',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->mobilityError($validator->errors()->first(), 422);
        }

        $oldStatus = $promo->status;

        /*
         * COMPLETING NEEDS A DESIGNATION TO WRITE, AND THE SCHEMA DISAGREES.
         *
         * `s_mobility_promotions.proposed_designation` is NULLABLE, but
         * completePromotionInProfile() writes it into `org_designation.designation`
         * which is NOT NULL. store() requires the field, so a promotion created
         * through the API always has one - but a row from an import, a seed or a
         * direct edit does not, and completing it raises
         *
         *     SQLSTATE[23000] 1048 Column 'designation' cannot be null
         *
         * INSIDE the transaction, so the status change rolls back too. The user
         * presses Complete, sees a 500, and nothing happens - with no indication
         * that the missing field is the reason.
         *
         * Refused here instead, before the transaction, naming the field.
         */
        if ($request->input('status') === 'Completed' && $oldStatus !== 'Completed'
            && trim((string) $promo->proposed_designation) === '') {
            return $this->mobilityError(
                'This promotion has no proposed designation, so it cannot be completed - '
                . 'completing it writes that designation onto the employee record. '
                . 'Set one first.',
                422
            );
        }

        // One transaction: the promotion row and the two HR-master writes below
        // describe one fact. See store() for why.
        DB::transaction(function () use ($promo, $request, $context, $oldStatus) {
            $changes = [
                'status' => $request->input('status'),
                'updated_by' => $context['user_id'],
            ];

            /*
             * ONLY WRITE REMARKS WHEN THEY WERE SENT - see the identical guard in
             * MobilityTransferController::update(). Unconditionally assigning
             * $request->input('remarks') meant that pressing Complete, which
             * sends only a status, NULLED the justification for the promotion.
             *
             * has(), not filled(): an explicit empty string is a deliberate
             * clearing and is still honoured. Only an absent key means "leave it".
             */
            if ($request->has('remarks')) {
                $changes['remarks'] = $request->input('remarks');
            }

            $promo->update($changes);

            if ($promo->status === 'Completed' && $oldStatus !== 'Completed') {
                $this->completePromotionInProfile($promo);
            }
        });

        return $this->mobilityResponse($promo, 'Promotion updated successfully');
    }

    private function completePromotionInProfile(MobilityPromotion $promo)
    {
        // 1. Update tbluser grade/allocated_standards if the proposed designation is a job role
        $jobroleId = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $promo->sub_institute_id)
            ->whereNull('deleted_at')
            ->where('jobrole', $promo->proposed_designation)
            ->value('id');

        /*
         * A LOOKUP MISS MUST NOT WRITE THE NAME. THIS WAS `$jobroleId ?: $name`.
         *
         * `tbluser.allocated_standards` holds a NUMERIC s_user_jobrole id - 21 of
         * 21 populated rows in tenant 6, verified. Every join that reads it
         * compares against that id. Writing the typed job-role NAME on a lookup
         * miss therefore corrupts the employee's role for every one of those
         * joins, silently, and the row still looks plausible on screen.
         *
         * This is not theoretical: completing one promotion during testing set a
         * real employee's allocated_standards to 'Senior Analyst' where the
         * correct value was '4342'. It had to be restored from the other host.
         *
         * So a miss leaves the column ALONE. The mobility record still completes
         * and still carries the intended role in its own column - the employee's
         * master row simply is not rewritten with something the rest of the
         * system cannot read. The job-role picker (rather than free text) is the
         * other half of this fix, on the client.
         */

        /*
         * Only touched when the designation resolves to a real job role. A
         * promotion whose designation is not a job role (many are not - "Senior
         * Analyst" is a title, not necessarily a catalogue entry) still records
         * the promotion and still updates org_designation below; it just does not
         * overwrite the employee's role id with a name.
         */
        if ($jobroleId) {
            DB::table('tbluser')
                ->where('id', $promo->user_id)
                ->where('sub_institute_id', $promo->sub_institute_id)
                ->update([
                    'allocated_standards' => $jobroleId,
                    'updated_at' => now(),
                ]);
        } else {
            Log::info('Promotion completed without changing the job role: designation is not a job role', [
                'promotion'   => $promo->id,
                'designation' => $promo->proposed_designation,
                'tenant'      => $promo->sub_institute_id,
            ]);
        }

        // 2. Update org_designation record
        $existing = DB::table('org_designation')
            ->where('user_id', $promo->user_id)
            ->where('sub_institute_id', $promo->sub_institute_id)
            ->first();

        if ($existing) {
            DB::table('org_designation')
                ->where('id', $existing->id)
                ->update([
                    'designation' => $promo->proposed_designation,
                    'level' => $promo->proposed_grade,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('org_designation')->insert([
                'user_id' => $promo->user_id,
                'sub_institute_id' => $promo->sub_institute_id,
                'designation' => $promo->proposed_designation,
                'level' => $promo->proposed_grade,
                'branch' => 'Main',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * TELL THE REST OF THE SYSTEM THE ROLE CHANGED.
         *
         * `employee.role_assigned` was emitted by exactly ONE place - the HR
         * Employee Directory screen (EmployeeDirectoryController:387) - so a
         * promotion completed through Mobility & Succession changed the
         * employee's designation and grade and told nobody.
         *
         * That matters because the event is consumed: LearningAssigner reacts to
         * `employee.role_assigned` by assigning the new role's MANDATORY courses
         * (LearningAssigner:92,178). Someone moved by HR through the directory
         * got their new role's training; the identical move made through this
         * module did not, and nothing reported the difference.
         *
         * Emitted INSIDE the caller's transaction, alongside the tbluser and
         * org_designation writes, because the event and the state change are one
         * fact - the contract EventRecorder exists to keep.
         */
        $this->events->record(
            'employee.role_assigned',
            (int) $promo->sub_institute_id,
            'employee',
            (int) $promo->user_id,
            $promo->updated_by ? (int) $promo->updated_by : null,
            [
                'source'               => 'mobility.promotion',
                'promotion_id'         => (int) $promo->id,
                'current_designation'  => $promo->current_designation,
                'proposed_designation' => $promo->proposed_designation,
                'proposed_grade'       => $promo->proposed_grade,
                'jobrole_id'           => $jobroleId ?: null,
            ]
        );
    }
}

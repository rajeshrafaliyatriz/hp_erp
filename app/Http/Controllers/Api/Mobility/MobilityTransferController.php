<?php

namespace App\Http\Controllers\Api\Mobility;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Mobility\MobilityTransfer;
use App\Http\Controllers\Api\Mobility\Concerns\ResolvesMobilityContext;

class MobilityTransferController extends Controller
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

        $query = MobilityTransfer::where('sub_institute_id', $subInstituteId);

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
            'from_department_id' => 'nullable|integer',
            'to_department_id' => 'required|integer',
            'from_jobrole' => 'nullable|string|max:191',
            'to_jobrole' => 'required|string|max:191',
            'effective_date' => 'required|date',
            'status' => 'required|string|in:Pending,Approved,Completed,Cancelled',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->mobilityError($validator->errors()->first(), 422);
        }

        // The subject must belong to the caller's organisation. `required|integer`
        // proved only that a number was sent; the tenant guard lived solely in the
        // WHERE clause of the profile write, so a foreign user_id updated nobody
        // while still producing a transfer record. See MobilityPromotionController.
        $subjectInTenant = DB::table('tbluser')
            ->where('id', (int) $request->input('user_id'))
            ->where('sub_institute_id', $subInstituteId)
            ->exists();

        if (!$subjectInTenant) {
            return $this->mobilityError('Employee not found.', 404);
        }

        // Department names, tenant-scoped. Without the predicate another
        // organisation's department name was copied into this transfer record.
        $fromDept = $request->input('from_department_id')
            ? DB::table('hrms_departments')
                ->where('id', $request->input('from_department_id'))
                ->where('sub_institute_id', $subInstituteId)
                ->value('department')
            : null;
        $toDept = DB::table('hrms_departments')
            ->where('id', $request->input('to_department_id'))
            ->where('sub_institute_id', $subInstituteId)
            ->value('department');

        if (!$toDept) {
            return $this->mobilityError('Destination department not found.', 404);
        }

        // One transaction: the transfer row and the tbluser write are one fact.
        $transfer = DB::transaction(function () use ($validator, $subInstituteId, $fromDept, $toDept, $actorId) {
            $transfer = MobilityTransfer::create(array_merge($validator->validated(), [
                'sub_institute_id' => $subInstituteId,
                'from_department' => $fromDept,
                'to_department' => $toDept,
                'created_by' => $actorId,
            ]));

            if ($transfer->status === 'Completed') {
                $this->completeTransferInProfile($transfer);
            }

            return $transfer;
        });

        return $this->mobilityResponse($transfer, 'Transfer recorded successfully', 201);
    }

    public function update(Request $request, $id)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $transfer = MobilityTransfer::where('sub_institute_id', $context['sub_institute_id'])
            ->find($id);

        if (!$transfer) {
            return $this->mobilityError('Transfer record not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:Pending,Approved,Completed,Cancelled',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->mobilityError($validator->errors()->first(), 422);
        }

        $oldStatus = $transfer->status;

        // One transaction: the transfer row and the tbluser write are one fact.
        DB::transaction(function () use ($transfer, $request, $context, $oldStatus) {
            $changes = [
                'status' => $request->input('status'),
                'updated_by' => $context['user_id'],
            ];

            /*
             * REMARKS ARE ONLY WRITTEN WHEN THEY WERE SENT.
             *
             * This used to be an unconditional `'remarks' => $request->input('remarks')`,
             * and the client does not send remarks on a status change - so pressing
             * Complete OVERWROTE THE JUSTIFICATION WITH NULL, in a column rendered
             * immediately left of the button that did it. The transfer completed,
             * the row went green, and the reason the person was moved was gone.
             *
             * has() rather than filled(): sending an explicit empty string is a
             * deliberate clearing and must still be honoured. Only an ABSENT key
             * means "leave it alone". This is the same shape as the F-69b fix in
             * talent_jobpostingcontroller::update().
             */
            if ($request->has('remarks')) {
                $changes['remarks'] = $request->input('remarks');
            }

            $transfer->update($changes);

            if ($transfer->status === 'Completed' && $oldStatus !== 'Completed') {
                $this->completeTransferInProfile($transfer);
            }
        });

        return $this->mobilityResponse($transfer, 'Transfer updated successfully');
    }

    private function completeTransferInProfile(MobilityTransfer $transfer)
    {
        // Auto-resolve jobrole_id for target jobrole in s_user_jobrole if it exists
        $jobroleId = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $transfer->sub_institute_id)
            ->whereNull('deleted_at')
            ->where('jobrole', $transfer->to_jobrole)
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

        // The department move always applies - it is an id either way, and it is
        // the substance of a transfer.
        $changes = [
            'department_id' => $transfer->to_department_id,
            'updated_at' => now(),
        ];

        if ($jobroleId) {
            $changes['allocated_standards'] = $jobroleId;
        } else {
            Log::info('Transfer completed without changing the job role: no match in s_user_jobrole', [
                'transfer'   => $transfer->id,
                'to_jobrole' => $transfer->to_jobrole,
                'tenant'     => $transfer->sub_institute_id,
            ]);
        }

        DB::table('tbluser')
            ->where('id', $transfer->user_id)
            ->where('sub_institute_id', $transfer->sub_institute_id)
            ->update($changes);

        /*
         * TELL THE REST OF THE SYSTEM THE ROLE CHANGED.
         *
         * `employee.role_assigned` was emitted by exactly ONE place - the HR
         * Employee Directory screen (EmployeeDirectoryController:387) - so a
         * promotion or transfer completed through Mobility & Succession changed
         * the employee's department and job role and told nobody.
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
            (int) $transfer->sub_institute_id,
            'employee',
            (int) $transfer->user_id,
            $transfer->updated_by ? (int) $transfer->updated_by : null,
            [
                'source'             => 'mobility.transfer',
                'transfer_id'        => (int) $transfer->id,
                'from_department_id' => $transfer->from_department_id,
                'to_department_id'   => $transfer->to_department_id,
                'from_jobrole'       => $transfer->from_jobrole,
                'to_jobrole'         => $transfer->to_jobrole,
                'jobrole_id'         => $jobroleId ?: null,
            ]
        );
    }

}

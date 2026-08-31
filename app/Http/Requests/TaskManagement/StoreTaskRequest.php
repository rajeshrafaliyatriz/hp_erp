<?php

namespace App\Http\Requests\TaskManagement;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation for the legacy task-create form.
 *
 * front_desk\taskController::store was type-hinted against this class but it
 * was never committed, so every create through that endpoint died before the
 * controller ran. The rules stay deliberately close to what the form actually
 * posts - the same three shapes (single, multiUser, BulkTask) the controller
 * branches on - rather than tightening anything the old frontend still relies
 * on.
 */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_title' => 'required|string|max:255',
            'task_description' => 'nullable|string|max:10000',
            // Comma-separated user ids, or a department id when the form
            // assigns by department instead of by person.
            'TASK_ALLOCATED_TO' => 'nullable|string|max:2000',
            'manageby' => 'nullable',
            // System priorities plus any custom priority the tenant defined.
            'selType' => 'nullable|string|max:100',
            'repeat_days' => 'nullable|integer|min:1|max:365',
            'repeat_until' => 'nullable|date',
            'TASK_DATE' => 'nullable|date',
            'KRA' => 'nullable|string|max:1000',
            'KPA' => 'nullable|string|max:1000',
            'skills' => 'nullable|string|max:2000',
            'skill_id' => 'nullable|string|max:2000',
            'observation_point' => 'nullable|string|max:2000',
            /*
             * The job-role task this was assigned FROM, when the assign form
             * picked it out of the catalogue. It is what lets the employee's
             * task detail find the written procedure, because `eso` is filed
             * under the same id.
             *
             * SHAPE ONLY HERE. Whether the id actually belongs to the caller's
             * organisation is checked in insertTaskWithReference, because that
             * needs the resolved tenant and this rule set does not have it.
             * An `exists` rule here would validate the row exists SOMEWHERE,
             * which is precisely the cross-tenant hole it looks like it closes.
             */
            'jobrole_task_id' => 'nullable|integer|min:1',
            'formType' => 'nullable|string|max:30',
            'task_details' => 'nullable|json',
            'idempotency_key' => 'nullable|string|max:100',
            'TASK_ATTACHMENT' => 'nullable|file|max:5120|extensions:jpg,jpeg,png,pdf,doc,docx',
            'sub_institute_id' => 'required',
            'syear' => 'required',
        ];
    }

    /** JSON errors, not a redirect - every caller of this endpoint is an API client. */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status_code' => 0,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}

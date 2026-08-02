<?php

namespace App\Http\Controllers\Api\TaskManagement;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Create-task with an idempotency key: retrying the same request (flaky
 * network, double click, queue redelivery) returns the task the first attempt
 * created instead of a duplicate.
 */
class IdempotentTaskController extends LegacyTaskController
{
    public function store(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make(
            $request->all(),
            $this->rules() + ['idempotency_key' => 'required|string|max:100']
        );

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $key = (string) $request->input('idempotency_key');

        $existing = DB::table('task_management_idempotency_keys')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('idempotency_key', $key)
            ->first();

        if ($existing) {
            // 200, not 201: nothing was created by THIS request.
            return $this->ok('Task already created for this idempotency key.', [
                'id' => (string) $existing->task_id,
                'replayed' => true,
            ]);
        }

        $response = parent::store($request);

        // Record the key only when the create actually succeeded.
        if ($response->getStatusCode() === 201) {
            $taskId = (int) (json_decode((string) $response->getContent(), true)['data']['id'] ?? 0);

            if ($taskId > 0) {
                DB::table('task_management_idempotency_keys')->insert([
                    'sub_institute_id' => $context['sub_institute_id'],
                    'idempotency_key' => $key,
                    'task_id' => $taskId,
                    'created_at' => now(),
                ]);
            }
        }

        return $response;
    }
}

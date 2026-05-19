<?php

namespace App\Http\Controllers\front_desk;

use App\Http\Controllers\Controller;
use App\Models\front_desk\taskModel;
use App\Models\user\tbluserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class BulkTaskController extends Controller
{
    /**
     * Bulk Task Import (supports CSV file or JSON task_details)
     */
    public function import(Request $request)
    {
        try {
            $type = $request->type;
            $user_id = $request->user_id;
            $sub_institute_id = ($type == "API")
                ? $request->sub_institute_id
                : $request->session()->get("sub_institute_id");

            if ($request->formType != "BulkTask") {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'Invalid formType. Use BulkTask'
                ], 400);
            }

            $insertCount = 0;
            $taskDetails = [];
            $skippedTasks = [];

            // Priority 1: CSV File Upload
            if ($request->hasFile('csv_file')) {
                $file = $request->file('csv_file');
                if (($handle = fopen($file->getRealPath(), "r")) !== false) {
                    $headers = fgetcsv($handle, 1000, ",");
                    // clean BOM if present in headers
                    if ($headers && count($headers) > 0) {
                        $headers[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $headers[0]);
                    }
                    $rowNum = 2;
                    while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                        if (count($headers) === count($row)) {
                            $rowAssoc = array_combine($headers, $row);
                            $rowAssoc['_row_num'] = $rowNum;
                            $taskDetails[] = $rowAssoc;
                        } else {
                            $skippedTasks[] = [
                                'row' => $rowNum,
                                'reason' => 'Row column count does not match header column count'
                            ];
                        }
                        $rowNum++;
                    }
                    fclose($handle);
                }
            }
            // Priority 2: JSON input
            elseif ($request->has('task_details')) {
                $taskDetails = json_decode($request->task_details, true) ?: [];
            }

            if (empty($taskDetails)) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'No task data provided'
                ], 400);
            }

            foreach ($taskDetails as $index => $taskValue) {
                $rowNum = $taskValue['_row_num'] ?? ($index + 1);

                $assignedName = trim($taskValue['assigned_to']
                    ?? $taskValue['Calendar Assigned To']
                    ?? $taskValue['Calendar Assigned to']
                    ?? '');

                $taskTitle = $taskValue['task_title']
                    ?? $taskValue['Calendar Subject']
                    ?? '';

                $taskDesc = $taskValue['task_description']
                    ?? $taskValue['Calendar Description']
                    ?? '';

                $completionRemarks = $taskValue['taskcompletation_remarks']
                    ?? $taskValue['Calendar Event Completion Remarks']
                    ?? '';

                // Resolve name → user ID
                // Remove extra spaces in case of "John  Doe"
                $assignedNameClean = trim(preg_replace('/\s+/', ' ', $assignedName));
                $nameParts = explode(' ', $assignedNameClean, 2);
                $firstName = $nameParts[0] ?? '';
                $lastName  = $nameParts[1] ?? '';

                $query = tbluserModel::where('sub_institute_id', $sub_institute_id)
                    ->whereRaw("LOWER(first_name) = LOWER(?)", [$firstName]);
                
                if ($lastName !== '') {
                    $query->whereRaw("LOWER(last_name) = LOWER(?)", [$lastName]);
                } else {
                    $query->where(function($q) {
                        $q->whereNull('last_name')->orWhere('last_name', '');
                    });
                }
                $matchedUser = $query->first();

                $allocatedUserId = $matchedUser ? $matchedUser->id : 0;

                if (!$allocatedUserId) {
                    $skippedTasks[] = [
                        'row' => $rowNum,
                        'task_title' => $taskTitle,
                        'assigned_to' => $assignedName,
                        'reason' => 'User not found in this sub_institute with the given name'
                    ];
                    continue; // Skip if user not found
                }

                $task_type = $taskValue['task_type'] ?? 'Medium';
                $task_date = $taskValue['TASK_DATE'] ?? date('Y-m-d');
                $repeat_days = (int)($taskValue['repeat_days'] ?? 1);
                $repeat_until = $taskValue['repeat_until'] ?? null;

                // Get repeating dates
                $dates = $this->getDatesWithoutSundays($task_type, $task_date, $repeat_days, $repeat_until);

                $baseTask = [
                    'sub_institute_id'         => $sub_institute_id,
                    'syear'                    => $request->syear ?? date('Y'),
                    'task_title'               => $taskTitle,
                    'task_description'         => $taskDesc,
                    'taskcompletation_remarks' => $completionRemarks,
                    'task_type'                => $task_type,
                    'TASK_ALLOCATED_TO'        => $allocatedUserId,
                    'created_by'               => $user_id,
                    'STATUS'                   => 'PENDING',
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ];

                if (!empty($dates)) {
                    foreach ($dates as $date) {
                        $data = $baseTask;
                        $data['TASK_DATE'] = $date;

                        $insert = taskModel::create($data);
                        if ($insert) {
                            $insertCount++;
                            $this->sendTaskNotification($allocatedUserId, $taskTitle, $user_id, $insert->id);
                        }
                    }
                } else {
                    $baseTask['TASK_DATE'] = $task_date;
                    $insert = taskModel::create($baseTask);
                    if ($insert) {
                        $insertCount++;
                        $this->sendTaskNotification($allocatedUserId, $taskTitle, $user_id, $insert->id);
                    }
                }
            }

            return response()->json([
                'status_code'     => $insertCount > 0 ? 1 : 0,
                'message'         => $insertCount > 0 ? "$insertCount tasks imported successfully" : "No tasks were imported",
                'imported'        => $insertCount,
                'skipped_count'   => count($skippedTasks),
                'skipped_details' => $skippedTasks
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status_code' => 0,
                'error'       => $e->getMessage(),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Calculate task dates without Sundays
     */
    private function getDatesWithoutSundays($type = "", $task_date = '', $repeat_days = 1, $repeat_until = null)
    {
        $startDate = $task_date ? \Carbon\Carbon::parse($task_date) : \Carbon\Carbon::now();
        $endDate = $repeat_until
            ? \Carbon\Carbon::parse($repeat_until)
            : ($task_date ? \Carbon\Carbon::parse($task_date) : \Carbon\Carbon::create($startDate->year, $startDate->month)->endOfMonth());

        $dates = [];

        if (in_array($type, ['High', 'Medium', 'Low'])) {
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDays($repeat_days)) {
                if (!$date->isSunday()) {
                    $dates[] = $date->format('Y-m-d');
                }
            }
        } else {
            $period = \Carbon\CarbonPeriod::create($startDate, $endDate);
            foreach ($period as $date) {
                if (!$date->isSunday()) {
                    $dates[] = $date->format('Y-m-d');
                }
            }
        }

        return $dates;
    }

    /**
     * Send FCM notification
     */
    private function sendTaskNotification($assigneeId, $taskTitle, $assignerId, $taskId)
    {
        $assignee = tbluserModel::find($assigneeId);
        if (!$assignee || empty($assignee->fcm_token)) {
            return;
        }

        try {
            $firebaseKeyPath = storage_path('app/firebase/gapstogrowth-ba988-firebase-adminsdk-fbsvc.json');
            if (!file_exists($firebaseKeyPath)) return;

            $factory = (new Factory)->withServiceAccount($firebaseKeyPath);
            $messaging = $factory->createMessaging();

            $assignerName = tbluserModel::where('id', $assignerId)->value('first_name');

            $message = CloudMessage::withTarget('token', $assignee->fcm_token)
                ->withNotification([
                    'title' => 'New Task Assigned',
                    'body'  => 'You have been assigned: ' . $taskTitle
                ])
                ->withData([
                    'type'       => 'task_assigned',
                    'task_title' => $taskTitle,
                    'assigned_by' => $assignerName,
                    'task_id'    => $taskId,
                    'employee_id' => $assigneeId
                ]);

            $messaging->send($message);
        } catch (\Exception $e) {
            Log::error("Bulk Task Notification Error: " . $e->getMessage());
        }
    }
}

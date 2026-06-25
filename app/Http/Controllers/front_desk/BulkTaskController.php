<?php

namespace App\Http\Controllers\front_desk;

use App\Http\Controllers\Controller;
use App\Models\front_desk\taskModel;
use App\Models\user\tbluserModel;
use Carbon\Carbon;
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
                    if ($headers && count($headers) > 0) {
                        $headers = array_map(function ($header) {
                            $header = (string) $header;
                            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

                            return trim($header);
                        }, $headers);
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
                $taskValue = $this->normalizeTaskRow($taskValue);

                $assignedName = trim((string) $this->getTaskFieldValue($taskValue, [
                    'assigned_to',
                    'employee_name_assigned_to',
                    'employee_name',
                    'calendar_assigned_to',
                    'calendar_assignedto',
                ], ''));
                $departmentName = trim((string) $this->getTaskFieldValue($taskValue, ['department'], ''));
                $jobRoleName = trim((string) $this->getTaskFieldValue($taskValue, ['jobrole', 'job_role'], ''));
                $observerName = trim((string) $this->getTaskFieldValue($taskValue, ['observer', 'observation_point'], ''));

                $taskTitle = trim((string) $this->getTaskFieldValue($taskValue, [
                    'task_title',
                    'calendar_subject',
                ], ''));

                $taskDesc = trim((string) $this->getTaskFieldValue($taskValue, [
                    'task_description',
                    'calendar_description',
                ], ''));

                $completionRemarks = trim((string) $this->getTaskFieldValue($taskValue, [
                    'taskcompletation_remarks',
                    'calendar_event_completion_remarks',
                ], ''));

                // Resolve name → user ID
                // Remove extra spaces in case of "John  Doe"
                if ($taskTitle === '') {
                    $skippedTasks[] = [
                        'row' => $rowNum,
                        'assigned_to' => $assignedName,
                        'reason' => 'Task title is missing'
                    ];
                    continue;
                }

                if ($assignedName === '') {
                    $skippedTasks[] = [
                        'row' => $rowNum,
                        'task_title' => $taskTitle,
                        'reason' => 'Assigned employee name is missing'
                    ];
                    continue;
                }

                $resolvedUser = $this->resolveTaskUser($sub_institute_id, $assignedName, $departmentName, $jobRoleName);
                $matchedUser = $resolvedUser['user'];

                if (!$matchedUser) {
                    $skippedTasks[] = [
                        'row' => $rowNum,
                        'task_title' => $taskTitle,
                        'assigned_to' => $assignedName,
                        'department' => $departmentName,
                        'job_role' => $jobRoleName,
                        'reason' => $resolvedUser['reason']
                    ];
                    continue;
                }

                $allocatedUserId = $matchedUser->id;
                $task_type = $this->normalizeTaskType($this->getTaskFieldValue($taskValue, [
                    'task_type',
                    'task_priority',
                ], 'Medium'));
                
                $task_date = $this->parseTaskDate($this->getTaskFieldValue($taskValue, [
                    'task_date',
                    'task_deadline',
                    'calendar_start_date_time',
                ])) ?? date('Y-m-d');

                $repeat_days = max((int) $this->getTaskFieldValue($taskValue, [
                    'repeat_days',
                    'repeat_once_in_every_days',
                ], 1), 1);
                $repeat_until = $this->parseTaskDate($this->getTaskFieldValue($taskValue, [
                    'repeat_until',
                    'calendar_end_date_time',
                ]));

                $calendarStatus = trim((string) $this->getTaskFieldValue($taskValue, ['calendar_status'], ''));
                $taskStatus = $this->mapTaskStatus($calendarStatus);

                $dates = $this->getDatesWithoutSundays($task_type, $task_date, $repeat_days, $repeat_until);

                $baseTask = [
                    'sub_institute_id'         => $sub_institute_id,
                    'SYEAR'                    => 2025,
                    'task_title'               => $taskTitle,
                    'task_description'         => $taskDesc,
                    'taskcompletation_remarks' => $completionRemarks,
                    'observation_point'        => $observerName ?: null,
                    'repeat_days'              => $repeat_days,
                    'task_type'                => $task_type,
                    'TASK_ALLOCATED'           => $user_id,
                    'TASK_ALLOCATED_TO'        => $allocatedUserId,
                    'created_by'               => $user_id,
                    'STATUS'                   => $taskStatus,
                    'CREATED_IP_ADDRESS'       => $request->ip(),
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ];

                if (!empty($dates)) {
                    foreach ($dates as $date) {
                        $data = $baseTask;
                        $data['TASK_DATE'] = $date;

                        $insert = $this->createTask($data);
                        if ($insert) {
                            $insertCount++;
                            $this->sendTaskNotification($allocatedUserId, $taskTitle, $user_id, $insert->id);
                        }
                    }
                } else {
                    $baseTask['TASK_DATE'] = $task_date;
                    $insert = $this->createTask($baseTask);
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
        $startDate = $task_date ? Carbon::parse($task_date) : Carbon::now();
        $endDate = $repeat_until
            ? Carbon::parse($repeat_until)
            : ($task_date ? Carbon::parse($task_date) : Carbon::create($startDate->year, $startDate->month)->endOfMonth());

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

    private function normalizeTaskRow(array $row): array
    {
        $normalizedRow = [];

        foreach ($row as $key => $value) {
            if ($key === '_row_num') {
                $normalizedRow[$key] = $value;
                continue;
            }

            $normalizedRow[$this->normalizeFieldKey($key)] = is_string($value)
                ? trim($value)
                : $value;
        }

        return $normalizedRow;
    }

    private function normalizeFieldKey($key): string
    {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9]+/', '', $key);
    }

    private function getTaskFieldValue(array $row, array $possibleKeys, $default = null)
    {
        foreach ($possibleKeys as $key) {
            $normalizedKey = $this->normalizeFieldKey($key);
            if (array_key_exists($normalizedKey, $row) && $row[$normalizedKey] !== '' && $row[$normalizedKey] !== null) {
                return $row[$normalizedKey];
            }
        }

        return $default;
    }

    private function normalizeTaskType($taskType): string
    {
        $taskType = ucfirst(strtolower(trim((string) $taskType)));

        return in_array($taskType, ['High', 'Medium', 'Low'], true) ? $taskType : 'Medium';
    }

    private function parseTaskDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d',
            'd-m-Y',
            'd/m/Y',
            'Y/m/d',
            'm/d/Y',
            'd-m-y',
            'd/m/y',
            'Y-m-d H:i:s',
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
            'd-m-Y h:i A',
            'd/m/Y h:i A',
            'Y-m-d H:i',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Exception $e) {
            }
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private function mapTaskStatus(string $calendarStatus): string
    {
        if ($calendarStatus === '') {
            return 'PENDING';
        }

        if (strcasecmp($calendarStatus, 'Planned') === 0 || strcasecmp($calendarStatus, 'Pending') === 0) {
            return 'Pending';
        }

        if (strcasecmp($calendarStatus, 'Held') === 0 || strcasecmp($calendarStatus, 'Completed') === 0) {
            return 'COMPLETED';
        }

        if (strcasecmp($calendarStatus, 'In Progress') === 0) {
            return 'IN PROGRESS';
        }

        if (strcasecmp($calendarStatus, 'On Hold') === 0) {
            return 'ON HOLD';
        }

        return 'PENDING';
    }

    private function resolveTaskUser($subInstituteId, string $assignedName, string $departmentName = '', string $jobRoleName = ''): array
    {
        $assignedName = trim(preg_replace('/\s+/', ' ', $assignedName));

        $searchAttempts = [];
        if ($departmentName !== '' || $jobRoleName !== '') {
            $searchAttempts[] = [$departmentName, $jobRoleName];
            if ($departmentName !== '') {
                $searchAttempts[] = [$departmentName, ''];
            }
            if ($jobRoleName !== '') {
                $searchAttempts[] = ['', $jobRoleName];
            }
        }
        $searchAttempts[] = ['', ''];

        foreach ($searchAttempts as [$departmentFilter, $jobRoleFilter]) {
            $query = tbluserModel::query()
                ->from('tbluser as u')
                ->leftJoin('hrms_departments as hd', 'hd.id', '=', 'u.department_id')
                ->leftJoin('s_user_jobrole as uj', 'uj.id', '=', 'u.allocated_standards')
                ->where('u.sub_institute_id', $subInstituteId)
                ->where('u.status', 1)
                ->select('u.*')
                ->where(function ($nameQuery) use ($assignedName) {
                    $nameQuery
                        ->whereRaw("LOWER(TRIM(CONCAT_WS(' ', COALESCE(u.first_name, ''), COALESCE(u.middle_name, ''), COALESCE(u.last_name, '')))) = ?", [strtolower($assignedName)])
                        ->orWhereRaw("LOWER(TRIM(CONCAT_WS(' ', COALESCE(u.first_name, ''), COALESCE(u.last_name, '')))) = ?", [strtolower($assignedName)])
                        ->orWhereRaw("LOWER(TRIM(u.first_name)) = ?", [strtolower($assignedName)]);
                });

            if ($departmentFilter !== '') {
                $query->whereRaw('LOWER(TRIM(hd.department)) = ?', [strtolower(trim($departmentFilter))]);
            }

            if ($jobRoleFilter !== '') {
                $query->whereRaw('LOWER(TRIM(uj.jobrole)) = ?', [strtolower(trim($jobRoleFilter))]);
            }

            $matches = $query->get();

            if ($matches->count() === 1) {
                return [
                    'user' => $matches->first(),
                    'reason' => null,
                ];
            }

            if ($matches->count() > 1) {
                return [
                    'user' => null,
                    'reason' => 'Multiple active users matched this employee name. Please make the employee name unique in the CSV.'
                ];
            }
        }

        return [
            'user' => null,
            'reason' => 'User not found in this sub institute with the given employee name, department, and job role'
        ];
    }

    private function createTask(array $data)
    {
        return taskModel::withoutEvents(function () use ($data) {
            return taskModel::create($data);
        });
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

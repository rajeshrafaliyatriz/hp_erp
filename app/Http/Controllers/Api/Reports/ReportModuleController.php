<?php

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Reports\ReportModuleRegistry;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * What the report builder's module/column pickers are populated from.
 * `scope` filters to one domain's modules (its key prefix, e.g. "crm.") so
 * the picker never offers a Task Management module while building a CRM
 * report or vice versa.
 */
class ReportModuleController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly ReportModuleRegistry $registry)
    {
    }

    /** GET /api/reports/modules?scope={task_management|crm} */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $scope = (string) $request->input('scope');
        $prefix = $scope . '.';

        $modules = array_values(array_filter(
            $this->registry->all(),
            fn ($module) => str_starts_with($module->key, $prefix),
        ));

        return response()->json([
            'status' => true,
            'data' => [
                'modules' => array_map(fn ($m) => [
                    'key' => $m->key,
                    'label' => $m->label,
                    'joinable' => array_keys($m->joins),
                ], $modules),
            ],
        ]);
    }

    /** GET /api/reports/modules/{moduleKey}/fields */
    public function fields(Request $request, string $moduleKey)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        if (!$this->registry->exists($moduleKey)) {
            return response()->json(['status' => false, 'message' => 'Unknown module.'], 404);
        }

        $fields = $this->registry->fieldsFor($moduleKey, (int) $identity['sub_institute_id']);

        return response()->json([
            'status' => true,
            'data' => [
                'fields' => array_map(fn ($f) => [
                    'key' => $f->key,
                    'label' => $f->label,
                    'type' => $f->type,
                    'groupable' => $f->groupable,
                    'aggregatable' => $f->aggregatable,
                    'is_custom_field' => $f->isCustomField,
                    'is_default_column' => $f->defaultColumn,
                ], $fields),
            ],
        ]);
    }
}

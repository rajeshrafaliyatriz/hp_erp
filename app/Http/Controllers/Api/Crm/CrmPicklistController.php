<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock + admin-edited picklist values for Leads/Contacts/Organizations/
 * Campaigns — shared across all 4 modules, so it is its own small
 * controller rather than living on one entity controller.
 */
class CrmPicklistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('crm_picklist_values')->where('status', true);

        if ($module = $request->input('module')) {
            $query->where('module', $module);
        }

        $rows = $query->orderBy('module')->orderBy('field_key')->orderBy('sort_order')->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->module][$row->field_key][] = [
                'value' => $row->value,
                'label' => $row->label,
                'sortOrder' => (int) $row->sort_order,
                'isDefault' => (bool) $row->is_default,
            ];
        }

        return response()->json(['status' => 1, 'message' => 'Picklist values.', 'data' => $grouped]);
    }
}

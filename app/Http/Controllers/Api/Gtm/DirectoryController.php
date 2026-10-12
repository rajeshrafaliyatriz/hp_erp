<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The people and teams a GTM screen can assign or filter by - read from this organisation's own user
 * and department records, never from the request. No contact details are returned: an id and a name.
 */
class DirectoryController extends Controller
{
    use ResolvesApiIdentity;

    public function show(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        return response()->json(['status' => 1, 'data' => [
            'users' => DB::table('tbluser')->where('sub_institute_id', $t)->where('status', 1)->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'department_id'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name), 'department_id' => $u->department_id])->values(),
            'departments' => DB::table('hrms_departments')->where('sub_institute_id', $t)->whereNull('deleted_at')->where('status', 1)->orderBy('department')
                ->get(['id', 'department'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->department])->values(),
        ]]);
    }
}

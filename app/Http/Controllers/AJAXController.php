<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\libraries\skillLibraryModel;
use function App\Helpers\is_mobile;
use App\Models\libraries\jobroleSkillModel;
use App\Models\libraries\industryModel;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\libraries\jobroleModel;
use App\Models\libraries\userSkills;
use App\Models\DynamicModel;
use Validator;
use Schema;
use DB;

class AJAXController extends Controller
{
    public function GetTableData(Request $request){
    	
        if (!$request->has('table')) {
            return response()->json(['error' => 'Table name is required'], 400);
        }

        // Get the table name from the request
        $table = $request->table;

        // Validate if the table exists
        if (!Schema::hasTable($table)) {
            return response()->json(['error' => 'Invalid table name'], 400);
        }

        // Start query
        $query = DB::table($table);

        // Apply filters if provided
        if ($request->has('filters') && is_array($request->filters)) {
            foreach ($request->filters as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }
        }

        // Fetch data
        $data = $query->get();

        // Check if data is empty
	    if ($data->isEmpty()) {
	        return response()->json(['message' => 'Data not found'], 404);
	    }

        return response()->json($data);
    }

    public function searchSkill(Request $request){
        $type=$request->type;
        if($type=='API'){
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'searchWord' => 'required',
            ]);

            if($validator->fails()){
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        if($request->has('searchType') && $request->searchType=="jobrole"){
            // echo "here";exit;
            $res['searchData'] = jobroleModel::where('jobrole', 'like', '%'.$request->searchWord.'%')->where('industries','like','%'.$request->org_type.'%') ->pluck('jobrole')
            ->values();
        }else{
            // echo "else here";exit;
            $res['searchData'] = skillLibraryModel::where('title', 'like', '%'.$request->searchWord.'%')->get();
        }
        if($res['searchData']->isNotEmpty()){
            $res['status_code'] = 1;
            $res['message'] = 'Search results found';
        }else{
            $res['status_code'] = 0;
            $res['message'] = 'Search results filed to found';
        }
        return is_mobile($type, 'skill_library.index', $res,'redirect');
    }

}

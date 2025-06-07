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
use Illuminate\Support\Facades\Schema; // Import the Schema facade
use Illuminate\Database\Schema\Blueprint; 
use DB;

class AJAXController extends Controller
{
    public function GetTableData(Request $request)
    {
        // 1. Basic validation for table name presence
        if (!$request->has('table')) {
            return response()->json(['error' => 'Table name is required'], 400);
        }

        // Get the table name from the request
        $table = $request->table;

        // 2. IMPORTANT: Validate table name format to prevent SQL Injection
        // Only allow alphanumeric characters and underscores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return response()->json(['error' => 'Invalid table name format.'], 400);
        }

        // 3. Manually validate if the table exists to bypass Schema::hasTable()
        try {
            $tableExists = DB::table('information_schema.tables')
                             ->where('table_schema', DB::raw('DATABASE()')) // Current database
                             ->where('table_name', $table)
                             ->exists();

            if (!$tableExists) {
                return response()->json(['error' => 'Table "' . $table . '" does not exist.'], 404);
            }
        } catch (\Exception $e) {
            // Catch database connection errors or other unexpected issues during the check
            \Log::error('Database error checking table existence: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'An internal server error occurred while validating the table.'], 500);
        }

        // Start query using the validated table name
        $query = DB::table($table);

        // Apply filters if provided
        if ($request->has('filters') && is_array($request->filters)) {
            foreach ($request->filters as $column => $value) {
                // 4. IMPORTANT: Validate column name format for security
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                    // Skip invalid column names or return an error
                    \Log::warning('Attempted to filter by invalid column name: ' . $column);
                    continue; // Skip this filter
                    // OR: return response()->json(['error' => 'Invalid column name format in filters.'], 400);
                }

                // 5. Manually validate if the column exists to bypass Schema::hasColumn()
                try {
                    $columnExists = DB::table('information_schema.columns')
                                      ->where('table_schema', DB::raw('DATABASE()'))
                                      ->where('table_name', $table)
                                      ->where('column_name', $column)
                                      ->exists();

                    if ($columnExists) {
                        $query->where($column, $value);
                    } else {
                        // Log or handle case where filter column doesn't exist
                        \Log::warning('Attempted to filter by non-existent column: ' . $column . ' on table ' . $table);
                        // Optionally, you might want to return an error here if a non-existent column is critical
                        // return response()->json(['error' => 'Column "' . $column . '" does not exist in table "' . $table . '".'], 400);
                    }
                } catch (\Exception $e) {
                    \Log::error('Database error checking column existence: ' . $e->getMessage(), ['exception' => $e]);
                    return response()->json(['error' => 'An internal server error occurred while validating a filter column.'], 500);
                }
            }
        }

        // Fetch data
        try {
            $data = $query->get();
        } catch (\Exception $e) {
            // Catch errors during data fetching (e.g., malformed queries, database down)
            \Log::error('Database error fetching data for table ' . $table . ': ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'An internal server error occurred while fetching data.'], 500);
        }


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

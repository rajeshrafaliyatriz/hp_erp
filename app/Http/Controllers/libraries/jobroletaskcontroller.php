<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\libraries\jobroletask;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;

class jobroletaskcontroller extends Controller
{
    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */


     public function index(Request $request)
    {
        try {
            $type = $request->type; // API or web

            if ($type == 'API') {
                // validate token
                $token = $request->input('token');
                if (!$token) {
                    return response()->json(['message' => 'Token not provided'], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                // validate required params
                $validator = Validator::make($request->all(), [
                    'sub_institute_id' => 'required',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'message' => $validator->errors()->first()
                    ], 400);
                }

                $sub_institute_id = $request->sub_institute_id;

                // fetch jobrole data from table
                $jobroles = DB::table('s_user_jobrole_task as a')
                            ->select('a.id', 'a.task_category', 'a.sub_institute_id',)
                            ->where('a.sub_institute_id', $sub_institute_id)
                            ->whereNotNull('a.task_category')
                            ->whereNull('a.deleted_at')
                            ->groupBy('a.task_category')
                            ->get();


                return response()->json([
                    'message' => 'Job roles fetched successfully',
                    'data'    => $jobroles
                ], 200);
            }

            // if type != API (web or mobile)
            $res['jobroles'] = DB::table('s_user_jobrole_task')
                    ->select('id', 'task_category', 'sub_institute_id')
                    ->get();
            return is_mobile($type, 'jobroletask.index', $res, 'view');

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

     public function store(Request $request)
    {
    $type = $request->type;

    if ($type == "API") {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $sub_institute_id = $request->get('sub_institute_id');


          $validator = Validator::make($request->all(), [
            'task_category' => ['required','string','max:255',
                \Illuminate\Validation\Rule::unique('s_user_jobrole_task', 'task_category')
                    ->where(function ($query) use ($sub_institute_id) {
                        return $query->where('sub_institute_id', $sub_institute_id)
                                     ->whereNull('deleted_at'); // if using soft deletes
                    }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objjobrole = new jobroletask();
            $objjobrole->task_category = $request->task_category;
            $objjobrole->sub_institute_id = $sub_institute_id;
            $objjobrole->created_by = $request->user_id;

            if ($objjobrole->save()) {
                return response()->json(['message' => 'category added successfully !!','data' => $objjobrole], 200);
            }

            return response()->json(['message' => 'Something went wrong !!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}

     

 /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($task_category)
    {
        //
    }

     public function update(Request $request,$task_category)
    {
        
       $getAllCategory = jobroletask::where(['sub_institute_id'=>$request->sub_institute_id,    'task_category'=>$task_category])  ->get();

        if($getAllCategory){
            $update = jobroletask::where(['sub_institute_id'=>$request->sub_institute_id,'task_category'=>$task_category])->update(['updated_by' => $this->g2gActorId($request),
                                'updated_at'=>now(),
                                'task_category' => $request->task_category]);

                                     if($update){
                 return response()->json([
                'message' => 'category updated successfully',
                 'data'    => $task_category
            ], 200);
                    }
            return response()->json([
                'message' => 'Failed to update',
                 'data'    => $task_category
            ], 401);
        }
        return $getAllCategory;
    

    }

    

    public function destroy(Request $request, $task_category)
    {
    $getAllCategory = jobroletask::where(['sub_institute_id'=>$request->sub_institute_id,'task_category'=>$task_category])->get();    
        
        if($getAllCategory){
            $update = jobroletask::where(['sub_institute_id'=>$request->sub_institute_id,'task_category'=>$task_category])
                        ->update(['deleted_by' => $this->g2gActorId($request),
                                'deleted_at'=>now(),
                                'task_category' => $request->task_category]);

                                     if($update){
                 return response()->json([
                'message' => 'category deleted successfully',
                 'data'    => $task_category
            ], 200);
                    }
            return response()->json([
                'message' => 'Failed to delete',
                 'data'    => $task_category
            ], 401);
        }
        return $getAllCategory;
}

}

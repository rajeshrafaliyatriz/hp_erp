<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\libraries\jobroletexonomy;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;

class jobroletexonomycontroller extends Controller
{
  
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
                        'status_code' => 0,
                        'message' => $validator->errors()->first()
                    ], 400);
                }

                $sub_institute_id = $request->sub_institute_id;

                // fetch jobrole data from table
                $jobroles = DB::table('s_user_jobrole as a')
                            ->select('a.id', 'a.jobrole_category', 'a.sub_institute_id', 'a.status')
                            ->where('a.sub_institute_id', 1)
                            ->whereNotNull('a.jobrole_category')
                            ->whereNull('a.deleted_at')
                            ->groupBy('a.jobrole_category')
                            ->get();


                return response()->json([
                    'message' => 'Job roles fetched successfully',
                    'data'    => $jobroles
                ], 200);
            }

            // if type != API (web or mobile)
            $res['jobroles'] = DB::table('s_user_jobrole')
                    ->select('id', 'jobrole_category', 'sub_institute_id', 'status')
                    ->get();
            return is_mobile($type, 'jobroletexonomy.index', $res, 'view');

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

        // $validator = Validator::make($request->all(), [
        //     'jobrole_category' => 'required|string|max:255',
        // ]);

          $validator = Validator::make($request->all(), [
            'jobrole_category' => ['required','string','max:255',
                // ensure unique per sub_institute_id
                \Illuminate\Validation\Rule::unique('s_user_jobrole', 'jobrole_category')
                    ->where(function ($query) use ($sub_institute_id) {
                        return $query->where('sub_institute_id', $sub_institute_id)
                                     ->whereNull('deleted_at'); // if using soft deletes
                    }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objjobrole = new jobroletexonomy();
            $objjobrole->jobrole_category = $request->jobrole_category;
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
    public function show($jobrole_category)
    {
        //
    }

    public function update(Request $request, $jobrole_category,)
    {
        // try {
        //     $category = jobroletexonomy::where('jobrole_category',$jobrole_category);
        // // return $category->get();
        //     return response()->json($category);

        //     if (!$category) {
        //         return response()->json(['message' => 'category not found'], 404);
        //     }
        //     // Update only the fields you send
            
        //     $update = $category->update(['updated_by' => $request->user_id,
        //                         'updated_at'=>now(),
        //                         'category' => $request->jobrole_category]);
        //     if(!$update){
        //         return response()->json(['message' => 'Invalid updated'], 401);
        //             }
        //     return response()->json([
        //         'message' => 'category updated successfully',
        //          'data'    => $category
        //     ], 200);

        // } catch (\Exception $e) {
        //     return response()->json(['error' => $e->getMessage()], 500);
        // }

        $getAllCategory = jobroletexonomy::where(['sub_institute_id'=>$request->sub_institute_id,    'jobrole_category'=>$jobrole_category])  ->get();

        if($getAllCategory){
            $update = jobroletexonomy::where(['sub_institute_id'=>$request->sub_institute_id,'jobrole_category'=>$jobrole_category])->update(['updated_by' => $request->user_id,
                                'updated_at'=>now(),
                                'jobrole_category' => $request->jobrole_category]);

                                     if($update){
                 return response()->json([
                'message' => 'category updated successfully',
                 'data'    => $jobrole_category
            ], 200);
                    }
            return response()->json([
                'message' => 'Failed to update',
                 'data'    => $jobrole_category
            ], 401);
        }
        return $getAllCategory;
    }


    public function destroy(Request $request, $jobrole_category)
// {
//     try {
//         $category = jobroletexonomy::where('jobrole_category', $jobrole_category);

//         if (!$category) {
//             return response()->json(['message' => 'Category not found'], 404);
//         }

//         // $deletedBy = $request->input('deleted_by', null);
    
   
//         $category->update(['deleted_by' => $request->user_id,
//                             'deleted_at'=>now()]);

//         return response()->json([
//             'message'    => 'Category deleted successfully',
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json(['error' => $e->getMessage()], 500);
//     }
// }
{

    $getAllCategory = jobroletexonomy::where(['sub_institute_id'=>$request->sub_institute_id,'jobrole_category'=>$jobrole_category])->get();    
        
        if($getAllCategory){
            $update = jobroletexonomy::where(['sub_institute_id'=>$request->sub_institute_id,'jobrole_category'=>$jobrole_category])->update(['updated_by' => $request->user_id,
                                'deleted_at'=>now(),
                                'jobrole_category' => $request->jobrole_category]);

                                     if($update){
                 return response()->json([
                'message' => 'category deleted successfully',
                 'data'    => $jobrole_category
            ], 200);
                    }
            return response()->json([
                'message' => 'Failed to delete',
                 'data'    => $jobrole_category
            ], 401);
        }
        return $getAllCategory;
}
}

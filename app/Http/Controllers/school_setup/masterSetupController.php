<?php

namespace App\Http\Controllers\school_setup;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\school_setup\subjectModel;
use App\Models\school_setup\standardModel;
use App\Models\school_setup\academic_sectionModel;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\DB;
use function App\Helpers\ValidateInsertData;
use Laravel\Sanctum\PersonalAccessToken;

use Validator;

class masterSetupController extends Controller
{
    public function index(Request $request){
        // echo "<pre>";print_r(session()->get('org_type'));exit;
        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');
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
                'user_id' => 'required',
            ]);

            if($validator->fails()){
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $res = $this->getData($request,$sub_institute_id);               
        $res['status_code'] = 1;
        $res['message'] = "SUCCESS";
        // $res['grade'] = $grade;
        // $res['data'] = $data;        
        return is_mobile($type,'school_setup/show_subject',$res,"view");  
    }

    public function getData($request,$sub_institute_id){
        // $sub_institute_id = $request->session()->get('sub_institute_id');
        $res['subject_data'] =  subjectModel::where(['sub_institute_id'=>$sub_institute_id])->orderBy('subject_name')->get();      
        $res['grade'] = academic_sectionModel::where('sub_institute_id',$sub_institute_id)->orderBy('sort_order')->get();
        $res['standard'] = standardModel::where('sub_institute_id',$sub_institute_id)->orderBy('sort_order')->get();
        return $res;
    }

    public function create(){
        return view('school_setup/add_subject');
    }
    public function store(Request $request){
        // echo "<pre>";print_r($request->all());exit;
        $sub_institute_id = $request->session()->get('sub_institute_id'); 
        $marking_period_id = $request->session()->get('term_id');
        
        //Check if Subject Already Exist or not
        $exist = $this->check_exist($request->get('subject_name'),$sub_institute_id);       
        if($exist == 0)
        {       
            $sub = new subjectModel([
                'subject_name' => $request->get('subject_name'),
                'subject_type' => $request->get('subject_type') != '' ? $request->get('subject_type') : "",
                'subject_code' => $request->get('subject_code'),
                'short_name' => $request->get('short_name'),
                'sub_institute_id' => $sub_institute_id,
                'marking_period_id'=>session()->get('term_id') ?? null,
                'status' => "1",            
            ]);
                 
            $sub->save();
            $res = array(
                "status_code" => 1,
                "message" => "Subject Added Successfully",
            );
        }
        else
        {
            $res = array(
                "status_code" => 0,
                "message" => "Subject Already Exist",
            );
        }

        $type = $request->input('type');
        return is_mobile($type, "subject_master.index", $res, "redirect");
    }
    
    public function check_exist($subject_name,$sub_institute_id)
    {           
        $subject_name = strtoupper($subject_name);
        
        $data = DB::select("SELECT count(*) as tot FROM subject WHERE sub_institute_id = '".$sub_institute_id."'
        AND UPPER(subject_name) = '".$subject_name."'");
        $total_count = $data[0]->tot;
        return $total_count;
    }
    
    public function edit(Request $request,$id){
        $type = $request->input('type');
        $sub_data = subjectModel::find($id)->toArray();
        return is_mobile($type, "school_setup/add_subject", $sub_data, "view");

    }
    public function update(Request $request,$id){
        ValidateInsertData('subject','update');        
        $sub_institute_id = $request->session()->get('sub_institute_id'); 

        //Check if Subject Already Exist or not
        $exist = $this->check_exist($request->get('subject_name'),$sub_institute_id);       
        if($exist == 0)
        {               
            $data = array(
                'subject_name' => $request->get('subject_name'),
                'subject_type' => $request->get('subject_type'),
                'subject_code' => $request->get('subject_code'),
                'short_name' => $request->get('short_name'),
                'marking_period_id' =>session()->get('term_id') ?? null,                
                'sub_institute_id' => $sub_institute_id,            
            );
            subjectModel::where(["id" => $id])->update($data);
            $res = array(
                "status_code" => 1,
                "message" => "Subject Updated Successfully",
            );
        }
        else
        {
            $res = array(
                "status_code" => 0,
                "message" => "Subject Already Exist",
            );
        }
        $type = $request->input('type');
        return is_mobile($type, "subject_master.index", $res, "redirect");
    }
    public function destroy(Request $request,$id){
        $type = $request->input('type');
        subjectModel::where(["id" => $id])->delete();
        $res['status_code'] = "1";
        $res['message'] = "Subject Deleted Successfully";
        return is_mobile($type, "subject_master.index", $res);
    }


    public function insert_data(Request $request){
        $type=$request->type;
        $master = $request->Slsection;
        $marking_period_id = 0;                

        $sub_institute_id = session()->get('sub_institute_id');
        $user_id = session()->get('user_id');

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
                'user_id' => 'required',
            ]);

            if($validator->fails()){
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        // for acadmey 
        if($master == 1){
            $ac_title = $request->ac_title;
            $ac_short_name = $request->ac_short_name;
            $ac_sort_order = $request->ac_sort_order;
            $ac_shift = $request->ac_shift;
            $ac_medium = $request->ac_medium;

            if(!empty($ac_title)){
                $check = academic_sectionModel::where(['title'=>$ac_title,'sub_institute_id'=>$sub_institute_id])->count();
                if($check > 0){
                    return redirect()->back()->with('failed','Academic Section Already Exists !');
                }else{
                
                    $data = academic_sectionModel::insert([
                        "sub_institute_id"=>$sub_institute_id, 
                        "title"=>$ac_title, 
                        "short_name"=>$ac_short_name, 
                        "sort_order"=>$ac_sort_order, 
                        "shift"=>$ac_shift, 
                        "medium"=>$ac_medium,
                        "created_by"=>$user_id,
                        'created_at'=>now(),
                    ]);
                    // return $data;
                    if($data == true){
                        return redirect()->back()->with('success','Academic Section Added Successfully !');
                    }else{
                        
                        return redirect()->back()->with('failed','Academic Section Failed to Add !');   
                    }
                
                }
        }else{
            // return "is empty";
                return redirect()->back()->with('failed','Some Fields are empty !');
        }
        // return back()->with('success','Academy');
        }


        // for standard 
        if($master == 2){
            $st_grade_id = $request->st_grade ; 
            $st_name= $request->st_name;
            $st_short_name=$request->st_short_name;
            $st_sort_order=$request->st_sort_order;
            $st_medium=$request->st_medium;
            $st_course_duration=$request->st_course_duration;
            $st_next_grade_id=$request->st_next_grade;
            $st_next_standard_id =$request->st_next_standard;

            if(!empty($st_name)){
            $data = standardModel::insert([
                    "grade_id"=>$st_grade_id,
                    "name"=>$st_name,
                    "short_name"=>$st_short_name,
                    "sort_order"=>$st_sort_order,
                    "medium" => $st_medium,
                    "sub_institute_id"=>$sub_institute_id,
                    "course_duration"=>$st_course_duration,
                    "next_grade_id"=>$st_next_grade_id ?? null,
                    "next_standard_id"=>$st_next_standard_id ?? null,
                    "marking_period_id"=> $marking_period_id ?? null,
                    "created_by"=>$user_id,
                    'created_at'=>now(),
                ]);
                if($data== true){
                    return redirect()->back()->with('success','Standard Added Successfully !');
                }else{
                    return redirect()->back()->with('failed','Standard Failed To Add !');
                }
                // return $data;
            }else{
                return redirect()->back()->with('failed','Some Fields are empty !');
            }
        // return back()->with('success','standard');
        }

        // for division
        if($master == 3){
            return redirect()->back()->with('success','Subject Added Successfully');
        } 
  
        
        // for subject
        if($master == 4){
        // return back()->with('success','Subject');
            $exist = $this->check_exist($request->get('subject_name'),$sub_institute_id);       
        if($exist == 0)
        {       
            $sub = subjectModel::insert([
                'subject_name' => $request->get('subject_name'),
                'subject_type' => $request->get('subject_type') != '' ? $request->get('subject_type') : "",
                'subject_code' => $request->get('subject_code'),
                'short_name' => $request->get('short_name'),
                'sub_institute_id' => $sub_institute_id,
                'marking_period_id'=>$marking_period_id ?? null,
                'status' => "1",     
                "created_by"=>$user_id,
                'created_at'=>now(),       
            ]);
                 
            // $sub->save();    
            return redirect()->back()->with('success','Subject Added Successfully');
        }
        else
        {
            return redirect()->back()->with('failed','Subject Already Exist');

        }

        }
    }
}

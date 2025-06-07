<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

if (!function_exists('is_mobile')) {

    function is_mobile($type, $url = null, $data = null, $redirect_type = "redirect")
    {
        if ($type == "API") {
                if (isset($data["status_code"])) {
                    $data["status"] = strtoupper($data["status_code"]);
                    unset($data["status_code"]);
                }

                // Recursively clean UTF-8
                array_walk_recursive($data, function (&$value) {
                    if (is_string($value)) {
                        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                    }
                });

                return response()->json($data);
            }

        else {

            if ($redirect_type == 'redirect') {

                return redirect()->route($url)->with(['data' => $data]);
            } else {
                if ($redirect_type == 'route_with_message') {

                    return route($url)->with(['data' => $data]);
                }
                // added on 24-03-2025 for id
                else if ($redirect_type == 'route_with_id') {
                    return redirect()->to(url($url))->with(['data' => $data]);
                } 
                else {
                    if ($redirect_type == 'view') {

                        return view($url, ['data' => $data]);
                    }
                }
            }
        }
    }

    if (!function_exists('SearchChain')) {

    function SearchChain($col, $multiple, $listed_drop, $grade_val = "", $std_val = "", $div_val = "")
    {
        $path = $_SERVER['HTTP_REFERER'] ?? URL::current();

            if ($path) {
                $parsedUrl = parse_url($path);
                
                if (isset($parsedUrl['path'])) {
                    $pathParts = pathinfo($parsedUrl['path']);
                    
                    if (isset($pathParts['filename'])) {
                        $module_name = $pathParts['filename'];
                    }
                    if($parsedUrl['path'] == '/lms/question_paper/create' || $parsedUrl['path'] == '/lms/question_paper/search'){
                        $module_name = 'question_paper';
                    }
                    if($parsedUrl['path'] == '/student/student_homework_submission/create'){
                        $module_name = 'student_homework_submission';
                    }
                    $path = "/student/student_homework/create";
                    $keyword = "student_homework";
                    
                    if (strpos($path, $keyword) !== false) {
                        $module_name = "student_homework";
                    }
                }
            }
            $module_array = [
                '1' => 'student_homework',
                '2' => 'marks_entry',
                '3' => 'dicipline',
                '4' => 'lmsExamwise_progress_report',
                '5' => 'questionReport',
                '6' => 'parent_communication',
                '7' => 'question_paper',
                '8' => 'co_scholastic_marks_entry', 
                '9' => 'student_homework_submission', // 2024-07-25          
            ];
            // menu_ids to get class teacher class only
            if(session()->get('sub_institute_id')==195){
                $menu_ids = [80,102];
            }else{
                // $menu_ids = [80,102,156];
                $menu_ids=[];
            }
            // for student 01-01-2025 start
          
            // for student 01-01-2025 end
            
            $getClass=[];

            // START 07/09/2021 code for getting standard , grade , division according to timetable wise for homework module
            if (session()->get('user_profile_name') == 'Teacher') {
                $teacher_id = session()->get('user_id');
                $sub_institute_id = session()->get('sub_institute_id');
                $syear = session()->get('syear');

                $subject_teacher = DB::table('subject as s')
                    ->join('timetable as t', function ($join) {
                        $join->whereRaw('t.subject_id = s.id AND t.sub_institute_id = s.sub_institute_id');
                    })->selectRaw('s.id,s.subject_name,t.*')
                    ->where('t.teacher_id', $teacher_id)
                    ->where('t.syear', $syear)
                    ->where('t.sub_institute_id', $sub_institute_id)
                    ->groupByRaw('s.id,t.standard_id,t.academic_section_id,t.division_id')
                    ->orderBy('s.subject_name')->get()->toArray();

                $subjectTeacherGrdArr = $subjectTeacherStdArr = $subjectTeacherDivArr = array();
                if (count($subject_teacher) > 0) {
                    foreach ($subject_teacher as $k => $v) {
                        $subjectTeacherGrdArr[] = $v->academic_section_id;
                        $subjectTeacherStdArr[] = $v->standard_id;
                        $subjectTeacherDivArr[] = $v->division_id;
                    }
                }
                Session::put('subjectTeacherGrdArr', $subjectTeacherGrdArr);
                Session::put('subjectTeacherStdArr', $subjectTeacherStdArr);
                Session::put('subjectTeacherDivArr', $subjectTeacherDivArr);
            }
            // END 07/09/2021 code for getting standard , grade , division according to timetable wise for homework module

            // 10-01-2025 start supervisor rights
            else if (!in_array(session()->get('user_profile_name'),['Super Admin','Admin','Teacher','LMS Teacher','Student']))
            {
                $getUserData =tbluserModel::where('id',session()->get('user_id'))->first();
                if(!empty($getUserData) && isset($getUserData->allocated_standards) && $getUserData->allocated_standards!=''){
                    $getAllocatedStandard = DB::table('standard')->whereRaw('id IN ('.$getUserData->allocated_standards.')')
                    ->get()->toArray();
                
                    $subjectTeacherGrdArr = $subjectTeacherStdArr = array();
                    if (count($getAllocatedStandard) > 0) {
                        foreach ($getAllocatedStandard as $k => $v) {
                            if(!in_array($v->grade_id,$subjectTeacherGrdArr)){
                                $subjectTeacherGrdArr[] = $v->grade_id;
                            }
                            if(!in_array($v->id,$subjectTeacherStdArr)){
                                $subjectTeacherStdArr[] = $v->id;
                            }
                        }
                    }
                    Session::put('subjectTeacherGrdArr', $subjectTeacherGrdArr);
                    Session::put('subjectTeacherStdArr', $subjectTeacherStdArr);
                }
            }
            // 10-01-2025 end supervisor rights

            $explod_list = explode(',', $listed_drop);
            $grade_name = 'grade';
            $std_name = 'standard';
            $div_name = 'division';
            $batch_section = 'batchsection';

            if ($multiple == 'multiple') {
                $multiple = 'multiple="multiple"';
                $grade_name = 'grade[]';
                $std_name = 'standard[]';
                $div_name = 'division[]';
                $batch_section = 'batchsection[]';
            } else if ($multiple == 'required') {
                $multiple = 'required="required"';
            } else {
                if ($multiple == 'single') {
                    $multiple = '';
                }
            }

            $option = "<option value=''>Select</option>";

            $query = DB::table("academic_section");
            $query->where("sub_institute_id", session()->get('sub_institute_id'));
            //START Check for class teacher assigned standards
            $classTeacherGrdArr = session()->get('classTeacherGrdArr');
            if (isset($classTeacherGrdArr) && !in_array($module_name, $module_array)) {
                if (count($classTeacherGrdArr) > 0) {
                    $query->whereIn('id', $classTeacherGrdArr);
                } else {
                    $query->oRwhere('id', null);
                }
            }
            //  END Check for class teacher assigned standards      

            // added on 17-04-24 by uma, when menu where class teacher can see only their class students
            if(in_array(session()->get('right_menu_id'),$menu_ids) && session()->get('user_profile_name') == 'Teacher'){
                $query->whereIn('id', [$getClass->grade_id ?? 0 ]);
            }
            // for students 01-01-2025 start 
            elseif(session()->get('user_profile_name')=="Student"){
                $query->where('id', [$studentData->grade_id ?? 0 ]);
            }
            // for students 01-01-2025 end 
            else{
            //START Check for subject teacher assigned
                $subjectTeacherGrdArr = session()->get('subjectTeacherGrdArr');
                if (isset($subjectTeacherGrdArr) && (!isset($classTeacherGrdArr) || in_array($module_name, $module_array))) {
                    if (count($subjectTeacherGrdArr) > 0) {
                        $query->whereIn('id', $subjectTeacherGrdArr);
                    } else {

                        $query->oRwhere('id', null);
                    }
                }
            }
            //END Check for subject teacher assigned

            $academic_section = $query->pluck("title", "id");

            $g_id=[];
            foreach ($academic_section as $id => $val) {
                $selected = '';
                if (is_array($grade_val)) {
                    if (in_array($id, $grade_val)) {
                        $g_id[]=$id;                    
                        $selected = 'selected="selected"';
                    }
                } else {
                    if ($grade_val == $id) {
                        $selected = 'selected="selected"';
                    }
                }
                $option .= "<option $selected value=$id>$val</option>";
            }

            $std_option = "<option value=''>Select</option>";
            if ($grade_val != "") {
                if (is_array($grade_val)) {

                    $query = DB::table('standard');
                    $classTeacherStdArr = session()->get('classTeacherStdArr');
                    if($std_val!='' && is_array($std_val) && !empty($classTeacherStdArr)){
                        $query->whereIn("id", $std_val)->whereIn('grade_id',$g_id);                    
                    }else{
                        $query->whereIn("grade_id", $grade_val);
                    }
                    
                    //START Check for class teacher assigned standards
                    if (isset($classTeacherStdArr) && !in_array($module_name, $module_array)) {
                        if (count($classTeacherStdArr) > 0 && $std_val=='') {
                            $query->whereIn('id', $classTeacherStdArr);
                        }
                    }
                    //END Check for class teacher assigned standards

                    //START Check for subject teacher assigned
                    $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
                    if (isset($subjectTeacherStdArr) && (!isset($classTeacherStdArr) || in_array($module_name, $module_array))) {
                        if (count($subjectTeacherStdArr) > 0 && $std_val=='') {
                            $query->orwhereIn('id', $subjectTeacherStdArr);
                        } else {
                            $query->oRwhere('id', null);
                        }
                    }
                    // for students 01-01-2025 start 
                    if(session()->get('user_profile_name')=="Student"){
                        $query->where('id', [$studentData->standard_id ?? 0 ]);
                    }
                    // for students 01-01-2025 end 
                    $standard = $query->pluck("name", "id");

                } else {
                    $query = DB::table('standard');
                    $query->where("grade_id", $grade_val);

                    //START Check for class teacher assigned standards
                    $classTeacherStdArr = session()->get('classTeacherStdArr');
                    if (isset($classTeacherStdArr) && !in_array($module_name, $module_array)) {
                        if (count($classTeacherStdArr) > 0) {
                            $query->whereIn('id', $classTeacherStdArr);
                        } else {
                            $query->oRwhere('id', null);
                        }
                    }
                    //END Check for class teacher assigned standards

                    //START Check for subject teacher assigned
                    $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
                    if (isset($subjectTeacherStdArr) && (!isset($classTeacherStdArr) || in_array($module_name, $module_array))) {
                        if (count($subjectTeacherStdArr) > 0) {
                            // $query->orwhereIn('id',$subjectTeacherStdArr);
                            $query->whereIn('id', $subjectTeacherStdArr);
                        } else {
                            // $query->orwhere('id',null);
                            $query->oRwhere('id', null);
                        }
                    }
                    //END Check for subject teacher assigned
                    // for students 01-01-2025 start 
                    if(session()->get('user_profile_name')=="Student"){
                        $query->where('id', [$studentData->standard_id ?? 0 ]);
                    }
                    // for students 01-01-2025 end 
                    $standard = $query->pluck("name", "id");
                }

                foreach ($standard as $id => $val) {
                    $selected = '';
                    if (is_array($std_val)) {
                        if (in_array($id, $std_val)) {
                            $selected = 'selected="selected"';
                        }
                    } else {
                        if ($std_val == $id) {
                            $selected = 'selected="selected"';
                        }
                    }

                    $std_option .= "<option $selected value=$id>$val</option>";
                }
            }
            
            $div_option = "<option value=''>Select</option>";
            if ($std_val != "") {
                $division = [];
                foreach ($division as $id => $val) {
                    $selected = '';
                    if (is_array($div_val)) {
                        if (in_array($id, $div_val)) {
                            $selected = 'selected="selected"';
                        }
                    } else {
                        if ($div_val == $id) {
                            $selected = 'selected="selected"';
                        }
                    }

                    $div_option .= "<option $selected value=$id>$val</option>";
                }

            }

            //  //  batch val  //  //
            $batch_option = "<option value=''>Select</option>";
            $searchsection = 'Search Section';
            $grade = '<div class="col-md-' . $col . '">
                        <div class="form-group">
                            <label>' . get_string('searchsection', 'request') . ': </label>
                            <select name="' . $grade_name . '" id="grade" class="form-control" ' . $multiple . '>
                                ' . $option . '
                            </select>

                        </div>
                    </div>';
            //<h4 class="box-title after-none mb-0">Select Section:</h4>

            $std = '<div class="col-md-' . $col . '">
                        <div class="form-group">
                            <label>' . get_string('searchstandard', 'request') . ': </label>
                            <select name="' . $std_name . '" id="standard" class="form-control" ' . $multiple . '>
                                ' . $std_option . '
                            </select>
                        </div>
                    </div>';
            //<h4 class="box-title after-none mb-0">Select Standard:</h4>

            $div = ' <div class="col-md-' . $col . '">
                        <div class="form-group">
                            <label>' . get_string('searchdivision', 'request') . ': </label>
                            <select name="' . $div_name . '" id="division" class="form-control" ' . $multiple . '>
                                ' . $div_option . '
                            </select>

                        </div>
                    </div>';
            //<h4 class="box-title after-none mb-0">Select Division:</h4>

            //  //  batch val  //  //
            $batch = ' <div class="col-md-' . $col . '">
                        <div class="form-group">
                            <label>Select Batch:</label>
                            <select name="' . $batch_section . '" id="stdBatch" class="form-control" ' . $multiple . '>
                                ' . $batch_option . '
                            </select>

                        </div>
                    </div>';
            // <h4 class="box-title after-none mb-0">Select Division:</h4>

            $html = '';

            if (in_array('grade', $explod_list)) {
                $html .= $grade;
            }

            if (in_array('std', $explod_list)) {
                $html .= $std;
            }

            if (in_array('div', $explod_list)) {
                $html .= $div;
            }

            if (in_array('batch', $explod_list)) {
                $html .= $batch;
            }
            $html .= '';
            echo $html;
        }
    }

    if (!function_exists('LMSSearchChain')) {

    function LMSSearchChain(
        $col,
        $multiple,
        $prefix,
        $standard_id,
        $listed_drop,
        $std_val = "",
        $sub_val = "",
        $chapter_val = "",
        $topic_val = ""
    ) {
        $sub_institute_id = session()->get('sub_institute_id');
        $explod_list = explode(',', $listed_drop);
        $std_name = 'standard';
        $sub_name = 'subject';
        $chapter_name = 'chapter';
        $topic_name = 'topic';

        if ($multiple == 'multiple') {
            $multiple = 'multiple="multiple"';
            $std_name = 'standard[]';
            $sub_name = 'subject[]';
            $chapter_name = 'chapter[]';
            $topic_name = 'topic[]';
        } else {
            if ($multiple == 'single') {
                $multiple = '';
            } else {
                echo "Chain Option Error : Must Provide First Prameter As Single Dropdown Or Multiple.";
            }
        }

        $std_option = "";
        $extra = '';
        if ($prefix == "pre") {
            $extra = " id < $standard_id";
        } elseif ($prefix == "post") {
            $extra = " id > $standard_id";
        } elseif ($prefix == "cross-curriculum") {
            $extra = " 1 = 1";
        }

        $standard = DB::table("standard")
            ->where("sub_institute_id", $sub_institute_id)
            ->whereRaw($extra)
            ->pluck("name", "id");
        $std_option .= "<option value=''>Select Standard</option>";
        foreach ($standard as $id => $val) {
            $selected = '';
            if ($std_val == $id) {
                $selected = 'selected="selected"';
            }

            $std_option .= "<option $selected value=$id>$val</option>";
        }


        $div_option = "";
        $sub_option = "";
        $chapter_option = "";
        $topic_option = "";

        if ($std_val != "") {
            $subjects = DB::table('sub_std_map')
                ->join('subject', 'subject.id', '=', 'sub_std_map.subject_id')
                ->where("sub_std_map.standard_id", $std_val)
                ->pluck('subject.subject_name', 'subject.id');

            $sub_option = "<option value=''>Select Subject</option>";
            foreach ($subjects as $id => $val) {
                $selected = '';
                if ($sub_val == $id) {
                    $selected = 'selected="selected"';
                }

                $sub_option .= "<option $selected value=$id>$val</option>";
            }
        }

        if ($sub_val != "") {
            $chapters = DB::table('chapter_master')
                ->where([
                    'sub_institute_id' => session()->get('sub_institute_id'), 'subject_id' => $sub_val,
                    "standard_id" => $std_val,
                ])
                ->pluck('chapter_name', 'id');

            $chapter_option = "<option value=''>Select Chapter</option>";
            foreach ($chapters as $id => $val) {
                $selected = '';
                if ($chapter_val == $id) {
                    $selected = 'selected="selected"';
                }
                $chapter_option .= "<option $selected value=$id>$val</option>";
            }
        }

        if ($chapter_val != "") {
            $topic_list = DB::table('topic_master')
                ->where(['sub_institute_id' => session()->get('sub_institute_id'), 'chapter_id' => $chapter_val])
                ->pluck('name', 'id');

            $topic_option = "<option value=''>Select Topic</option>";
            foreach ($topic_list as $id => $val) {
                $selected = '';
                if ($topic_val == $id) {
                    $selected = 'selected="selected"';
                }
                $topic_option .= "<option $selected value=$id>$val</option>";
            }
        }

        $std = '<div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Standard</label>
                        <select name="' . $prefix . $std_name . '" id="' . $prefix . 'standard" class="form-control" ' . $multiple . '>
                            ' . $std_option . '
                        </select>
                    </div>
                </div>';

        $sub = ' <div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Subject</label>
                        <select name="' . $prefix . $sub_name . '" id="' . $prefix . 'subject" class="form-control" ' . $multiple . '>
                            ' . $sub_option . '
                        </select>
                    </div>
                </div>';

        $chapter = ' <div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Chapter</label>
                        <select name="' . $prefix . $chapter_name . '" id="' . $prefix . 'chapter" class="form-control" ' . $multiple . '>
                            ' . $chapter_option . '
                        </select>
                    </div>
                </div>';

        $topic = ' <div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Topic</label>
                        <select name="' . $prefix . $topic_name . '" id="' . $prefix . 'topic" class="form-control" ' . $multiple . '>
                            ' . $topic_option . '
                        </select>
                    </div>
                </div>';

        $html = '<div class="row">';

        if (in_array('std', $explod_list)) {
            $html .= $std;
        }

        if (in_array('sub', $explod_list)) {
            $html .= $sub;
        }

        if (in_array('chapter', $explod_list)) {
            $html .= $chapter;
        }

        if (in_array('topic', $explod_list)) {
            $html .= $topic;
        }

        $html .= '</div>';
        echo $html;
    }
}


    if (!function_exists('getDataWithId')) {
        function getDataWithId($id,$type){
            $name='-';
            if($type=="department"){
                $name = DB::table('hrms_departments')->where('id',$id)->value('department');
            }
            elseif($type=="employee"){
                $name = DB::table('tbluser')->where('id',$id)->selectRaw('CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name')->value('name');
            }
           
           return $name;
       }
    }
    if (!function_exists('get_string')) {

        function get_string($arg,$type='', $sub_institute_id = '')
        {
        
                return $arg;
        }
    }
}


if (!function_exists('sendNotification')) {
    function sendNotification($notification_arr)
    {
        // appNotificationModel::insert($notification_arr);
    }
}


if (!function_exists('SearchChainSubject')) {

    function SearchChainSubject($col, $multiple, $listed_drop, $grade_val = "", $std_val = "", $sub_val = "")
    {

        $explod_list = explode(',', $listed_drop);
        $grade_name = 'grade';
        $std_name = 'standard';
        $sub_name = 'subject';

        if ($multiple == 'multiple') {
            $multiple = 'multiple="multiple"';
            $grade_name = 'grade[]';
            $std_name = 'standard[]';
            $sub_name = 'subject[]';
        } else {
            if ($multiple == 'single') {
                $multiple = '';
            } else {
                echo "Chain Option Error : Must Provide First Prameter As Single Dropdown Or Multiple.";
            }
        }

        $option = "<option value=''>--Select Grade--</option>";

        $academic_section = DB::table("academic_section")
            ->where("sub_institute_id", session()->get('sub_institute_id'))
            ->pluck("title", "id");

        foreach ($academic_section as $id => $val) {
            $selected = '';
            if ($grade_val == $id) {
                $selected = 'selected="selected"';
            }

            $option .= "<option $selected value=$id>$val</option>";
        }

        $std_option = "";
        if ($grade_val != "") {
            $standard = DB::table("standard")
                ->where("grade_id", $grade_val)
                ->pluck("name", "id");
            foreach ($standard as $id => $val) {
                $selected = '';
                if ($std_val == $id) {
                    $selected = 'selected="selected"';
                }

                $std_option .= "<option $selected value=$id>$val</option>";
            }
        }

        $div_option = "";
        $sub_option = "";

        if ($std_val != "") {
            $subjects = DB::table('sub_std_map')
                ->join('subject', 'subject.id', '=', 'sub_std_map.subject_id')
                ->where("sub_std_map.standard_id", $std_val)
                ->pluck('subject.subject_name', 'subject.id');

            foreach ($subjects as $id => $val) {
                $selected = '';
                if ($sub_val == $id) {
                    $selected = 'selected="selected"';
                }

                $sub_option .= "<option $selected value=$id>$val</option>";
            }
        }

        $grade = '<div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Grade:</label>
                        <select name="' . $grade_name . '" id="gradeS" class="form-control" ' . $multiple . '>
                            ' . $option . '
                        </select>
                    </div>
                </div>';

        $std = '<div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Standard:</label>
                        <select name="' . $std_name . '" id="standardS" class="form-control" ' . $multiple . '>
                            ' . $std_option . '
                        </select>
                    </div>
                </div>';

        $sub = ' <div class="col-md-' . $col . '">
                    <div class="form-group">
                        <label for="title">Select Subject:</label>
                        <select name="' . $sub_name . '" id="subject" class="form-control" ' . $multiple . '>
                            ' . $sub_option . '
                        </select>
                    </div>
                </div>';
        $html = '<div class="row">';

        if (in_array('grade', $explod_list)) {
            $html .= $grade;
        }

        if (in_array('std', $explod_list)) {
            $html .= $std;
        }

        if (in_array('sub', $explod_list)) {
            $html .= $sub;
        }
        $html .= '</div>';
        echo $html;
    }
}
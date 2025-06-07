<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use App\Models\lms\answermasterModel;
use App\Models\lms\lmsOnlineExamAnswerModel;
use App\Models\lms\lmsOnlineExamModel;
use App\Models\lms\lmsQuestionMasterModel;
use App\Models\lms\questionpaperModel;
use App\Models\lms\topicModel;
use App\Models\lms\contentModel;
use GenTux\Jwt\GetsJwtToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use function App\Helpers\aut_token;


class lms_apiController extends Controller
{
    use GetsJwtToken;

    public function studentVirtualClassroomAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        return json_encode($res);
    }

    public function studentPortfolioAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        if ($student_id != "" && $sub_institute_id != "" && $syear != "") {
            $data = DB::table('lms_portfolio as p')
                ->leftJoin('tbluser as u', function ($join) {
                    $join->whereRaw('p.feedback_by = u.id')->where('u.status',1);   // 23-04-24 by uma
                })
                ->selectRaw("p.*, CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) AS teacher_name,
                    if(p.file_name = '','',concat('https://".$_SERVER['SERVER_NAME']."/storage/lms_portfolio/',p.file_name))
                    as file_name, DATE_FORMAT(p.created_at,'%d-%m-%Y') AS created_at")
                ->where('p.user_id', $student_id)
                ->where('p.syear', $syear)
                ->where('p.sub_institute_id', $sub_institute_id)->get()->toArray();

            $res['status'] = 1;
            $res['message'] = "Success";
            $res['data'] = $data;
        } else {
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        }

        return json_encode($res);
    }

    public function studentSocialCollabrativeAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

       
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        return json_encode($res);
    }

    public function studentSubjectAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        return json_encode($res);
    }

    
    public function studentContentAPI(Request $request) {
       try {
            if (!$this->jwtToken()->validate()) {
                $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());
                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());
            return response()->json($response, 401);
        }
                
        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");        
        $subject_id = $request->input("subject_id");        

                       
            if(!empty($finaldata) && count($finaldata)>0){
                $res['status'] = 1;
                $res['message'] = "Success";
                $res['data'] = $finaldata;   
            } else{
                $res['status'] = 0;
                $res['message'] = "No Data Found";
            }       
           
      
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        
        //return  \App\Helpers\is_mobile($type, "implementation", $res);
        return json_encode($res);       
    }

    public function studentQuestionPaperListAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");
        $subject_id = $request->input("subject_id");

        
        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        
        return json_encode($res);
    }


    public function studentQuestionPaperAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");
        $question_paper_id = $request->input("question_paper_id");

        if ($student_id != "" && $sub_institute_id != "" && $syear != "" && $question_paper_id != "") {
            $data['questionpaper_data'] = questionpaperModel::find($question_paper_id)->toArray();

            $attempted = DB::table('lms_online_exam as le')
                ->join('question_paper as qp', function ($join) use ($sub_institute_id, $syear) {
                    $join->whereRaw("qp.id = le.question_paper_id AND qp.sub_institute_id = '".
                        $sub_institute_id."' AND qp.syear = '".$syear."'");
                })->selectRaw('count(le.id)+1 as count_attempted')
                ->where('student_id', $student_id)
                ->where('question_paper_id', $question_paper_id)->get()->toArray();

            if ($data['questionpaper_data']['open_date'] <= date('Y-m-d H:i:s') && $data['questionpaper_data']['close_date'] >= date('Y-m-d H:i:s') && ($attempted[0]->count_attempted <= $data['questionpaper_data']['attempt_allowed'] || $data['questionpaper_data']['attempt_allowed'] == 0)) {

                $question_ids = explode(",", $data['questionpaper_data']['question_ids']);

                foreach ($question_ids as $key => $val) {
                    $question = lmsQuestionMasterModel::where("id", $val)->get()->toArray();
                    $finaldata['Question'][$val] = $question[0];

                    $answer_arr = answermasterModel::where([
                        "question_id" => $val, "sub_institute_id" => $sub_institute_id,
                    ])->get()->toArray();
                    if (count($answer_arr) > 0) {
                        foreach ($answer_arr as $anskey => $ansval) {
                            $finaldata['Question'][$val]['Answer'][] = $ansval;
                        }
                    }
                }

                $res['status'] = 1;
                $res['message'] = "Success";
                $res['data'] = $finaldata;
            } else {
                $res['status'] = 0;
                $res['message'] = "Not allowed";
            }
        } else {
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        }

        return json_encode($res);
    }


    public function studentAssessmentAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");
        $question_paper_id = $request->input("question_paper_id");

        if ($student_id != "" && $sub_institute_id != "" && $syear != "" && $question_paper_id != "") {
            // $data['attempted_data'] = lmsOnlineExamModel::where(['student_id'=>$student_id,'question_paper_id'=>$question_paper_id])
            //                             ->orderby('start_time')->get()->toArray();

            $data['attempted_data'] = DB::table('lms_online_exam as le')
                ->join('question_paper as qp', function ($join) use ($sub_institute_id, $syear) {
                    $join->whereRaw("qp.id = le.question_paper_id AND qp.sub_institute_id = '".
                        $sub_institute_id."' AND qp.syear = '".$syear."'");
                })->selectRaw('le.id,le.student_id,le.question_paper_id,le.total_right,le.total_wrong,
                    (le.total_right) as obtain_marks,le.start_time,le.created_at,le.id as online_exam_id,qp.paper_name')
                ->where('student_id', $student_id)
                ->where('question_paper_id', $question_paper_id)
                ->orderBy('start_time')->get()->toArray();
                
                // Check if $data['attempted_data'] is empty
                if (empty($data['attempted_data'])) {
                    $res['status'] = 0;
                    $res['message'] = "No attempted data found for the specified parameters.";
                } else{
                    $data['attempted_data'] = json_decode(json_encode($data['attempted_data']), true);
        //Rajesh = Hide PROGRESSBAR_DATA because API take too much time, and not required in mobile app....future perpective data display 
        /*
                    foreach ($data['attempted_data'] as $key => $val) {
                        $pdata = DB::select("SELECT *,'100' as total_percentage,
                            round(((a.right_answer*100)/total_question),2) as obtained_percentage from (
                            SELECT lt.parent_id,plt.name as parent_name,lt.id,lt.name,COUNT(mapping_type_id) as total_question,group_concat(e.question_id) as ques_list,
                            sum((case when e.ans_status = 'right' then '1' end)) as right_answer
                            FROM lms_question_mapping l
                            INNER JOIN lms_mapping_type lt ON lt.id = l.mapping_value_id
                            INNER JOIN lms_mapping_type plt ON plt.id = lt.parent_id
                            LEFT JOIN lms_online_exam_answer e on e.question_id = l.questionmaster_id and e.question_paper_id = '" . $val['question_paper_id'] . "' AND
                            e.student_id = '" . $val['student_id'] . "' and e.online_exam_id = '" . $val['id'] . "'
                            WHERE questionmaster_id IN (
                                    SELECT question_id
                                    FROM lms_online_exam_answer
                                    WHERE question_paper_id = '" . $val['question_paper_id'] . "' AND student_id = '" . $val['student_id'] . "'
                                    AND online_exam_id = '".$val['id']."'
                                )
                            GROUP BY mapping_value_id
                            ORDER BY mapping_type_id,mapping_value_id) as a
                        ");

                        $pdata_new = json_decode(json_encode($pdata), true);
                        foreach ($pdata_new as $pkey => $pval) {
                            $data['attempted_data'][$key]['PROGRESSBAR_DATA'][$pval['parent_name']][] = $pval;
                        }
                    }
        */
                    $res['status'] = 1;
                    $res['message'] = "Success";
                    $res['data'] = $data;
                }

        } else {
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        }

        //return  \App\Helpers\is_mobile($type, "implementation", $res);
        return json_encode($res);
    }

    public function studentLeaderBoardAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
    
        return json_encode($res);
    }


    public function studentTransportAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        if ($student_id != "" && $sub_institute_id != "" && $syear != "") {
            $data = DB::table('transport_map_student as s')
                ->join('transport_school_shift as f_ss', function ($join) {
                    $join->whereRaw('f_ss.id = s.from_shift_id AND f_ss.sub_institute_id = s.sub_institute_id');
                })->join('transport_vehicle as f_v', function ($join) {
                    $join->whereRaw('f_v.id = s.from_bus_id AND f_v.sub_institute_id = s.sub_institute_id');
                })->join('transport_stop as f_st', function ($join) {
                    $join->whereRaw('f_st.id = s.from_stop AND f_st.sub_institute_id = s.sub_institute_id');
                })->join('transport_school_shift as t_ss', function ($join) {
                    $join->whereRaw('t_ss.id = s.to_shift_id AND t_ss.sub_institute_id = s.sub_institute_id');
                })->join('transport_vehicle as t_v', function ($join) {
                    $join->whereRaw('t_v.id = s.to_bus_id AND t_v.sub_institute_id = s.sub_institute_id');
                })->join('transport_stop as t_st', function ($join) {
                    $join->whereRaw('t_st.id = s.to_stop AND t_st.sub_institute_id = s.sub_institute_id');
                })
                ->selectRaw('s.id,s.student_id,
                    f_ss.shift_title AS from_shift ,f_v.title AS from_bus ,f_st.stop_name AS from_stop_name,
                    t_ss.shift_title AS to_shift ,t_v.title AS to_bus ,t_st.stop_name AS to_stop_name')
                ->where('s.student_id', $student_id)
                ->where('s.syear', $syear)
                ->where('s.sub_institute_id', $sub_institute_id)->get()->toArray();

            $res['status'] = 1;
            $res['message'] = "Success";
            $res['data'] = $data;
        } else {
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        }

        return json_encode($res);
    }


    public function studentActivityStreamAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        return json_encode($res);
    }

    public function studentBookListAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        return json_encode($res);
    }

    public function studentSyllabusAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        //return  \App\Helpers\is_mobile($type, "implementation", $res);
        return json_encode($res);
    }

    public function studentQuestionPaperSaveAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = ['status' => '2', 'message' => 'Token Auth Failed', 'data' => []];

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];

            return response()->json($response, 401);
        }


        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");
        $question_paper_id = $request->input("question_paper_id");
        $question_list = $request->input("question_list");
        $given_ans = $request->input("given_ans");
        $original_ans = $request->input("original_ans");
        $total_marks = $request->input("total_marks");

        if ($student_id != "" && $sub_institute_id != "" && $syear != "" && $question_paper_id != "" && $question_list != "" &&
            $given_ans != "" && $original_ans != "" && $total_marks != "") {
            $given_ans_array = explode(",", $given_ans);
            $original_ans_array = explode(",", $original_ans);
            $question_list_array = explode(",", $question_list);

            //START Insert into lms_online_exam table
            $correct_ans = $wrong_ans = 0;
            foreach ($given_ans_array as $key => $val) {
                if ($val == $original_ans_array[$key]) {
                    $correct_ans++;
                } else {
                    $wrong_ans++;
                }
            }
            $tot_marks = $correct_ans + $wrong_ans;
            $lms_online_data = [
                "student_id"        => $student_id,
                "question_paper_id" => $question_paper_id,
                "total_right"       => $correct_ans,
                "total_wrong"       => $wrong_ans,
                "obtain_marks"      => $tot_marks,//$total_marks 08/06/2022 RAJESH
                "start_time"        => now(),
            ];

            lmsOnlineExamModel::insert($lms_online_data);
            $online_exam_id = DB::getPDO()->lastInsertId();
            //END Insert into lms_online_exam table


            //START Insert into lms_online_exam_answer table
            foreach ($question_list_array as $qkey => $qval) {
                $ans_status = "";
                if ($given_ans_array[$qkey] == $original_ans_array[$qkey]) {
                    $ans_status = "right";
                } else {
                    $ans_status = "wrong";
                }

                $lms_answer_data = array(
                    'question_paper_id' => $question_paper_id,
                    'online_exam_id'    => $online_exam_id,
                    'student_id'        => $student_id,
                    'question_id'       => $qval,
                    'answer_id'         => $given_ans_array[$qkey],
                    'ans_status'        => $ans_status,
                );
                lmsOnlineExamAnswerModel::insert($lms_answer_data);
            }
            //END Insert into lms_online_exam_answer table

            $res['status'] = 1;
            $res['message'] = "Success";
            $res['data'] = null;
        } else {
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        }

        //return  \App\Helpers\is_mobile($type, "implementation", $res);
        return json_encode($res);
    }

    public function studentAssessmentDetailAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());

            return response()->json($response, 401);
        }


        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");
        $online_exam_id = $request->input("online_exam_id");

        if ($student_id != "" && $sub_institute_id != "" && $syear != "" && $online_exam_id != "") {

            $data['attempted_data'] = DB::SELECT("SELECT le.id,le.student_id,le.question_paper_id,le.total_right,le.total_wrong,(le.total_right+le.total_wrong) as obtain_marks,le.start_time,le.created_at,le.id as online_exam_id,qp.paper_name
                 FROM lms_online_exam le
                 INNER JOIN question_paper qp ON qp.id = le.question_paper_id AND qp.sub_institute_id = '".$sub_institute_id."' AND qp.syear = '".$syear."'
                 WHERE student_id = '".$student_id."' AND le.id = '".$online_exam_id."'");


            $online_answer_data = DB::select("SELECT a.*, GROUP_CONCAT(am.answer) AS actual_answer,q.question_type_id,q.multiple_answer,
                (
                CASE
                WHEN question_type_id = 2 THEN IF(given_answer is null,'wrong','right')
                WHEN question_type_id = 1 AND multiple_answer = 0 THEN IF(given_answer=GROUP_CONCAT(am.answer),'right','wrong')
                WHEN question_type_id = 1 AND multiple_answer = 1 THEN IF(given_answer=GROUP_CONCAT(am.answer),'right','wrong')
                END
                ) AS right_wrong ,q.question_title
                FROM (
                SELECT loem.question_id,loem.ans_status,IFNULL(loem.narrative_answer, GROUP_CONCAT(IFNULL(lam.answer,'Not Attempted'))) AS given_answer
                FROM lms_online_exam_answer loem
                LEFT JOIN answer_master lam ON lam.question_id = loem.question_id AND lam.id = loem.answer_id
                WHERE loem.online_exam_id = '".$online_exam_id."' AND loem.student_id = '".$student_id."'
                GROUP BY loem.question_id) AS a
                INNER JOIN lms_question_master q ON q.id = a.question_id and q.status = 1
                LEFT JOIN answer_master am ON a.question_id = am.question_id AND correct_answer = 1
                GROUP BY am.question_id,a.question_id
            ");
            
            $data1 = []; // Define $data1 as an empty array before the loop
            foreach ($online_answer_data as $key => $val) {
                $new = array();

                $new[$val->question_id]['QUESTION_TEXT'] = $val->question_title;
                $new[$val->question_id]['RIGHT_WRONG'] = $val->right_wrong;
                $new[$val->question_id]['ACTUAL_ANSWER'] = $val->actual_answer;
                $new[$val->question_id]['GIVEN_ANSWER'] = $val->given_answer;

                $data1[] = (object) $new;
            }
            $data['online_answer_data'] = $data1;

            // Check if $data['attempted_data'] is empty
                if (empty($data['attempted_data']) || empty($data['online_answer_data'])) {
                    $res['status'] = 0;
                    $res['message'] = "No attempted data found for the specified parameters.";
                } else{
                    $res['status'] = 1;
                    $res['message'] = "Success";
                    $res['data'] = $data;
                }

        } else {
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        }

        //return  \App\Helpers\is_mobile($type, "implementation", $res);
        return json_encode($res);
    }


    public function lmsCategorywiseSubjectAPI(Request $request)
    {
        try {
            if (! $this->jwtToken()->validate()) {
                $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());

                return response()->json($response, 401);
            }
        } catch (\Exception $e) {
            $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());

            return response()->json($response, 401);
        }

        $student_id = $request->input("student_id");
        $sub_institute_id = $request->input("sub_institute_id");
        $syear = $request->input("syear");

        
            $res['status'] = 0;
            $res['message'] = "Parameter Missing";
        

        //return  \App\Helpers\is_mobile($type, "implementation", $res);
        return json_encode($res);
    }

    public function trizStandardAPI(Request $request)
    {
        $data = DB::table('standard as s')
            ->join('academic_section as a', function ($join) {
                $join->whereRaw('s.sub_institute_id = a.sub_institute_id AND s.grade_id = a.id');
            })
            ->where('s.sub_institute_id', '=', '1')
            ->where('a.title', '!=', 'OTHERS')->get()->toArray();

        $res['status'] = 1;
        $res['message'] = "Success";
        $res['data'] = $data;

        return json_encode($res);
    }

}

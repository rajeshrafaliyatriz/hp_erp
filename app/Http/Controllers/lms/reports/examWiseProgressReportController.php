<?php

namespace App\Http\Controllers\lms\reports;

use App\Http\Controllers\Controller;
use App\Models\lms\questionpaperModel;
use App\Models\school_setup\sub_std_mapModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use function App\Helpers\is_mobile;
use function App\Helpers\SearchStudent;

class examWiseProgressReportController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $type = $request->input('type');
        $res['status_code'] = 1;
        $res['message'] = "SUCCESS";
        $res['subject_data'] = array();
        $res['exams_data'] = array();

        return is_mobile($type, 'lms/reports/show_examwise_progress_report', $res, "view");
    }

    public function create(Request $request)
    {
        $grade = $request->input('grade');
        $standard = $request->input('standard');
        $division = $request->input('division');
        $subject = $request->input('subject');
        $exams = $request->input('exam_id');
        $exam_type = $request->input('exam_type');
        $type = $request->input('type');
        $marking_period_id = session()->get('term_id');

        if ($type == "API") {
            $sub_institute_id = $request->input('sub_institute_id');
            $syear = $request->input('syear');
        } else {
            $sub_institute_id = session()->get('sub_institute_id');
            $syear = session()->get('syear');
        }

        $student_data = SearchStudent($grade, $standard, $division, $sub_institute_id, $syear);

        $examData = questionpaperModel::where([
            'sub_institute_id' => $sub_institute_id, 'standard_id' => $standard, 'subject_id' => $subject, 'syear' => $syear,
        ])
            ->whereIn('id', $exams)
            ->orderby('id')
            ->get();

        $exam_ids = implode(',', $exams);

        $marks_array = $grade_array = $all_marks = array();
        
        $data =[];
//dd(DB::getQueryLog());

        $data = json_decode(json_encode($data), true);
        foreach ($data as $k => $v) {
            $explodeQuestion = explode(',',$v['question_paper_id']);
            foreach ($explodeQuestion as $key => $value) {
                $marks_array[$v['id']][$value] = $v['obtain_marks'];
            }
        }
        $maxCount = 0;
        
        foreach ($data as $k => $v) {
            $all_marks[$v['id']][$v['question_paper_id']] = $v['all_marks'];
            if (is_string($all_marks[$v['id']][$v['question_paper_id']])) {
                $elements = explode(',', $all_marks[$v['id']][$v['question_paper_id']]);
                $count = count($elements);
                $maxCount = max($maxCount, $count);
            }
        }

        $grade_data = DB::table('result_std_grd_maping as rgm')
            ->join('grade_master_data as gm', function ($join) {
                $join->whereRaw('gm.grade_id = rgm.grade_scale AND gm.sub_institute_id = rgm.sub_institute_id');
            })->selectRaw('gm.title,gm.breakoff')
            ->where('rgm.standard', $standard)
            ->where('rgm.sub_institute_id', $sub_institute_id)->get()->toArray();

        $grade_data = json_decode(json_encode($grade_data), true);

        $subject_data = sub_std_mapModel::where(['sub_institute_id' => $sub_institute_id, 'standard_id' => $standard])
            ->orderBy('display_name')->get()->toArray();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['student_data'] = $data;
        $res['marks_data'] = $marks_array;
        $res['all_marks_col']= $maxCount;
        $res['all_marks'] = $all_marks;
        $res['grade_data'] = $grade_data;
        $res['grade_id'] = $grade;
        $res['standard_id'] = $standard;
        $res['division_id'] = $division;
        $res['subject_id'] = $subject;
        $res['exam_id'] = $exams;
        $res['exam_type'] = $exam_type;
        $res['exams_data'] = $examData;
        $res['subject_data'] = $subject_data;
            // echo "<pre>";print_r($res['student_data']);exit;
        return is_mobile($type, "lms/reports/show_examwise_progress_report", $res, "view");
    }

    public function ajax_LMS_SubjectWiseExam(Request $request)
    {
        $std_id = $request->input("std_id");
        $sub_id = $request->input("sub_id");
        $sub_institute_id = session()->get("sub_institute_id");
        $syear = session()->get("syear");

        return questionpaperModel::where([
            'sub_institute_id' => $sub_institute_id, 'standard_id' => $std_id, 'subject_id' => $sub_id, 'syear' => $syear,
        ])->get()->toArray();
    }

}

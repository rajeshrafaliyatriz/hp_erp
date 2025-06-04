<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Models\lms\questionpaperModel;
use App\Models\school_setup\sub_std_mapModel;

use function App\Helpers\is_mobile;

class questionWiseReportController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $type = $request->input('type');
        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['subject_data'] = array();
        $res['exams_data'] = array();
        return is_mobile($type, "student/question_wise_report/show_question_wise_report", $res, "view");
    }

    /**
     * show_question_wise_report
     */
    public function show_question_wise_report(Request $request)
    {

        $type = $request->input('type');
        $grade = $request->grade;
        $standard = $request->standard;
        $division = $request->division;
        $subject = $request->subject;
        $order_by = $request->order_by;
        $question_paper_id = $request->exam;
        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $res['action']=$action = $request->action;
        // return $request;exit;
        $examData = questionpaperModel::where([
            'sub_institute_id' => $sub_institute_id, 'standard_id' => $standard, 'subject_id' => $subject,
        ])
            ->where('id', $question_paper_id)
            ->orderby('id')
            ->get();
        $queryResult = [];

// dd(DB::getQueryLog($queryResult));
        if ($queryResult) {
            $resultArr = [];
            foreach ($queryResult as $result) {
                $online_exam_id = $result->online_exam_id;
                $question_paper_id = $result->question_paper_id;

                if (!isset($resultArr[$question_paper_id])) {
                    $resultArr[$question_paper_id][$result->id][$online_exam_id][] = $result;
                } else {
                    if (isset($resultArr[$question_paper_id][$result->id])) {
                        $resultArr[$question_paper_id][$result->id][$online_exam_id][] = $result;
                    } else {
                        $resultArr[$question_paper_id][$result->id][$online_exam_id][] = $result;
                    }
                }
            }
        }

        $standard_name = DB::table('standard')->select('name')->where('id', $standard)->first();
        $division_name = DB::table('division')->select('name')->where('id', $division)->first();
        $subject_name = DB::table('subject')->select('subject_name')->where('id', $subject)->first();
        $subject_data = sub_std_mapModel::where(['sub_institute_id' => $sub_institute_id, 'standard_id' => $standard])
        ->orderBy('display_name')->get()->toArray();
        if (!empty($resultArr)) {
            $res['results'] = $resultArr;
        }
        $res['grade_id'] = $grade;
        $res['standard_id'] = $standard;
        $res['division_id'] = $division;
        $res['subject_id'] = $subject;
        $res['exam_id'] = $question_paper_id;
        $res['exams_data'] = $examData;
        $res['subject_data'] = $subject_data;
        if ($subject_name) {
            $res['subject_name'] = $subject_name->subject_name;
        }
        if ($standard_name) {
            $res['standard_name'] = $standard_name->name;
        }
        if ($division_name) {
            $res['division_name'] = $division_name->name;
        }

        return is_mobile($type, "student/question_wise_report/show_question_wise_report", $res, "view");
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return void
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return void
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return void
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return void
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return void
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return void
     */
    public function destroy($id)
    {
        //
    }
}

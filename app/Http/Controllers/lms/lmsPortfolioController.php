<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use App\Models\lms\chapterModel;
use App\Models\lms\portfolioModel;
use App\Models\lms\topicModel;
use GenTux\Jwt\GetsJwtToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Storage;


class lmsPortfolioController extends Controller
{
    use GetsJwtToken;

    /**
     * Display a listing of the resource.
     *
     * @param  Request  $request
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @return Response
     */
    public function index(Request $request)
    {
        $type = $request->input('type');
        $data = $this->getData($request);
        $res['status_code'] = 1;
        $res['message'] = "SUCCESS";
        $res['data'] = $data['portfolio_data'];

        if (strtoupper(session()->get('user_profile_name')) == "STUDENT") {
            return is_mobile($type, 'lms/show_student_lmsPortfolio', $res, "view");
        } else {
            if (strtoupper(session()->get('user_profile_name')) == "LMS TEACHER" || strtoupper(session()->get('user_profile_name')) == "TEACHER") {
                return is_mobile($type, 'lms/show_all_lmsPortfolio', $res, "view");
            }
        }

    }

    public function getData($request)
    {

        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');
        $user_id = $request->session()->get('user_id');
        $user_profile_id = $request->session()->get('user_profile_id');

        $data['portfolio_data'] = [];

        if (strtoupper(session()->get('user_profile_name')) == "STUDENT") {
            $data['portfolio_data'] = portfolioModel::select('lms_portfolio.*', DB::raw('date_format(created_at,"%d-%m-%Y") as created_at,
                CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) as teacher_name'))
                ->leftjoin("tbluser as u", function ($join) {
                    $join->on("u.id", "=", "lms_portfolio.feedback_by")
                        ->on("u.sub_institute_id", "=", "lms_portfolio.sub_institute_id")
                        ->where('u.status',1);  // 23-04-24 by uma
                })
                ->where([
                    'lms_portfolio.sub_institute_id' => $sub_institute_id, 'lms_portfolio.user_id' => $user_id,
                    'lms_portfolio.user_profile_id'  => $user_profile_id, 'lms_portfolio.syear' => $syear,
                ])
                ->get()->toArray();
        } else {
            if (strtoupper(session()->get('user_profile_name')) == "LMS TEACHER" || strtoupper(session()->get('user_profile_name')) == " TEACHER") {
                $data['portfolio_data'] = [];
            }
        }

        return $data;
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(Request $request)
    {
        $type = $request->input('type');
        $action = $request->get('action');
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');

        $data['action'] = $action;

        return is_mobile($type, 'lms/add_portfolio', $data, "view");
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');
        $user_profile_id = $request->session()->get('user_profile_id');
        $user_id = $request->session()->get('user_id');

        $newfilename = "";
        if ($request->hasFile('filename')) {
            $img = $request->file('filename');
            $filename = $img->getClientOriginalName();
            $ext = $img->getClientOriginalExtension();
            $size = $img->getSize();
            $newfilename = 'lms_'.date('Y-m-d_h-i-s').'.'.$ext;
            //$img->move(public_path().'/lms_content_file/',$newfilename);
            // $img->storeAs('public/lms_portfolio/', $newfilename); 20-05-24
            Storage::disk('digitalocean')->putFileAs('public/lms_portfolio/', $img, $newfilename, 'public');

        }

        $content = [
            'title'            => $request->get('title'),
            'description'      => $request->get('description'),
            'type'             => $request->get('type'),
            'file_name'        => $newfilename,
            'user_id'          => $user_id,
            'user_profile_id'  => $user_profile_id,
            'sub_institute_id' => $sub_institute_id,
            'syear'            => $syear,
        ];

        portfolioModel::insert($content);

        $res = array(
            "status_code" => 1,
            "message"     => "Portfolio Added Successfully",
        );
        $type = $request->input('type');

        return is_mobile($type, "lmsPortfolio.index", $res, "redirect");
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(Request $request, $id)
    {
        $type = $request->input('type');

        $sub_institute_id = $request->session()->get('sub_institute_id');
        $user_id = $request->session()->get('user_id');
        $user_profile_id = $request->session()->get('user_profile_id');


        $data['portfolio_data'] = portfolioModel::select('*',
            db::raw('date_format(created_at,"%d-%m-%Y") as created_at'))
            ->where([
                'sub_institute_id' => $sub_institute_id, 'user_id' => $user_id, 'user_profile_id' => $user_profile_id,
            ])
            ->take(10)
            ->get()->toArray();

        return is_mobile($type, "lms/view_portfolio", $data, "view");
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit(Request $request, $id)
    {
        $type = $request->input('type');
        $sub_institute_id = $request->session()->get('sub_institute_id');

        $data['portfolio_data'] = portfolioModel::find($id)->toArray();
        $data['action'] = $data['portfolio_data']['type'];

        return is_mobile($type, "lms/add_portfolio", $data, "view");
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');
        $user_id = $request->session()->get('user_id');
        $user_profile_id = $request->session()->get('user_profile_id');

        $image_data = array();
        if ($request->hasFile('filename')) {
            if ($request->has('hid_filename')) {
                unlink('storage'.$request->input('hid_filename'));
            }
            $img = $request->file('filename');
            $filename = $img->getClientOriginalName();
            $ext = $img->getClientOriginalExtension();
            $size = $img->getSize();
            $newfilename = 'lms_'.date('Y-m-d_h-i-s').'.'.$ext;
            //$img->move(public_path().'/lms_content_file/',$newfilename);
            // $img->storeAs('public/lms_portfolio/', $newfilename); 20-05-24
            Storage::disk('digitalocean')->putFileAs('public/lms_portfolio/', $img, $newfilename, 'public');

            $image_data = [
                'file_name' => $newfilename,
            ];
        }

        $data = [
            'title'            => $request->get('title'),
            'description'      => $request->get('description'),
            'user_id'          => $user_id,
            'user_profile_id'  => $user_profile_id,
            'sub_institute_id' => $sub_institute_id,
            'syear'            => $syear,
        ];

        $data = array_merge($data, $image_data);

        portfolioModel::where(["id" => $id])->update($data);

        $res = [
            "status_code" => 1,
            "message"     => "Portfolio Updated Successfully",
        ];
        $type = $request->input('type');

        return is_mobile($type, "lmsPortfolio.index", $res, "redirect");

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(Request $request, $id)
    {
        $type = $request->input('type');

        portfolioModel::where(["id" => $id])->delete();
        $res['status_code'] = "1";
        $res['message'] = "Portfolio Deleted Successfully";

        return is_mobile($type, "lmsPortfolio.index", $res);

    }

    public function ajax_LMS_SubjectwiseChapter(Request $request)
    {
        $sub_id = $request->input("sub_id");
        $std_id = $request->input("std_id");
        $sub_institute_id = $request->session()->get("sub_institute_id");

        return chapterModel::where([
            'chapter_master.sub_institute_id' => $sub_institute_id,
            'chapter_master.subject_id'       => $sub_id,
            'chapter_master.standard_id'      => $std_id,
        ])->get()->toArray();
    }

    public function ajax_LMS_ChapterwiseTopic(Request $request)
    {
        $chapter_id = $request->input("chapter_id");
        $chapter_ids = explode(",", $chapter_id);
        $sub_institute_id = $request->session()->get("sub_institute_id");

        return topicModel::whereIn("topic_master.chapter_id", $chapter_ids)
            ->where(['topic_master.sub_institute_id' => $sub_institute_id])
            ->get()->toArray();
    }

    public function ajax_lmsPortfolio_feedback(Request $request)
    {
        $user_id = $request->session()->get("user_id");
        foreach ($request->get('feedback') as $key => $val) {
            $data = [
                'feedback'    => $val,
                'feedback_by' => $user_id,
            ];
            portfolioModel::where(["id" => $key])->update($data);
        }

        $res = [
            "status_code" => 1,
            "message"     => "Portfolio Updated Successfully",
        ];
        $type = $request->input('type');

        return is_mobile($type, "lmsPortfolio.index", $res, "redirect");
    }

}

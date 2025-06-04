<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use App\Models\lms\doubtModel;
use GenTux\Jwt\GetsJwtToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function App\Helpers\aut_token;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Storage;

class lmsDoubtController extends Controller
{
    use GetsJwtToken;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
    }

    public function getData($request)
    {
    }


    /**
     * Show the form for creating a new resource.
     *
     * @param  Request  $request
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @return Response
     */
    public function create(Request $request)
    {
        $type = $request->input('type');
        $action = $request->get('action');
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');

      

        $data['action'] = $action;

        return is_mobile($type, 'lms/add_doubt', $data, "view");
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
            // $img->storeAs('public/lms_doubts/', $newfilename); 20-05-24
            Storage::disk('digitalocean')->putFileAs('public/lms_doubts/', $img, $newfilename, 'public');

        }

        $content = array(
            'subject_id'       => $request->get('subject'),
            'chapter_id'       => $request->get('chapter'),
            'topic_id'         => $request->get('topic'),
            'title'            => $request->get('title'),
            'description'      => $request->get('description'),
            'visibility'       => $request->get('visibility'),
            'file_name'        => $newfilename,
            'user_id'          => $user_id,
            'user_profile_id'  => $user_profile_id,
            'sub_institute_id' => $sub_institute_id,
            'syear'            => $syear,
        );

        doubtModel::insert($content);

        $res = [
            "status_code" => 1,
            "message"     => "Doubts Added Successfully",
        ];
        $type = $request->input('type');

        return is_mobile($type, "lmsPortfolio.index", $res, "redirect");
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return void
     */
    public function show(Request $request, $id)
    {
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return void
     */
    public function edit(Request $request, $id)
    {
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
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return void
     */
    public function destroy(Request $request, $id)
    {
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

}

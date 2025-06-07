<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use App\Models\lms\doubtModel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use function App\Helpers\is_mobile;

class lmsSocialCollabrotiveController extends Controller
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
        $res['message'] = "SUCCESS";
        $data = $this->getData($request);
        $res['doubt_data'] = $data['doubt_data'];
        $res['doubt_conversation_data'] = $data['doubt_conversation_data'];

        return is_mobile($type, 'lms/show_lmsSocialCollabrotivenew', $res, "view");
    }

    public function getData($request)
    {

        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');
        $user_id = $request->session()->get('user_id');
        $user_profile_id = $request->session()->get('user_profile_id');

        $data['doubt_data'] = $data['doubt_conversation_data'] = array();
       
            //START to Get Doubt
            $data['doubt_data'] = [];
            //END to Get Doubt

            foreach ($data['doubt_data'] as $key => $val) {
               
                $arr =[];

                $arr = json_decode(json_encode($arr), true);

                $data['doubt_conversation_data'][$val['id']] = $arr;
                //END to Get Doubt Conversation
            
        }

        return $data;
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return void
     */
    public function create(Request $request)
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return void
     */
    public function store(Request $request)
    {

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

}

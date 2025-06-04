<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use App\Models\lms\lmsContentCategoryModel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use function App\Helpers\is_mobile;

class courseController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
		// echo "<pre>";print_r(session()->all());exit;
        $data = $this->getData($request);
        $type = $request->input('type');
        $res['status_code'] = 1;
        $res['message'] = "SUCCESS";
        $res['lms_subject'] = $data['mycourse_arr'];
		$res['content_category'] = $data['content_category'];
		$res['sub_institute_id'] = session()->get('sub_institute_id');
        return is_mobile($type, 'lms/show_course', $res, "view");
    }

    public function getData($request)
	{
	    $sub_institute_id = session()->get('sub_institute_id');
	    $syear = session()->get('syear');
	    $user_profile_name = session()->get('user_profile_name');
	    $user_id = session()->get('user_id');
	    $mycourse_arr = [];

	    $extra = " 1=1 ";
	    
	    $getIsLms = DB::table('school_setup')
	        ->where('Id', $sub_institute_id)
	        ->value('is_Lms');

	    $sub_institute_id_by_lms = ($getIsLms == 'Y') ? "(s.sub_institute_id = 1 or s.sub_institute_id = $sub_institute_id)" : "s.sub_institute_id = $sub_institute_id";

	    if ($user_profile_name == 'Teacher') {
	        $arr = DB::table('sub_std_map as s')
	            ->selectRaw("STD.name AS standard_name,s.display_name AS subject_name,s.subject_id,STD.id AS standard_id,
	                s.display_image,GROUP_CONCAT(DISTINCT(CONCAT_WS('/',cp.chapter_name,cp.id))SEPARATOR '#') AS chapter_list,
	                IFNULL(s.subject_category,'My Course') AS content_category,s.sub_institute_id")
	            ->join('standard AS STD', 'STD.id', '=', 's.standard_id')
	            ->Join('timetable AS t', function ($join) use ($user_id, $syear, $sub_institute_id, $extra) {
	                $join->on('t.standard_id', '=', 's.standard_id')
	                    ->on('t.subject_id', '=', 's.subject_id')
	                    ->on('t.sub_institute_id', '=', 's.sub_institute_id')
	                    ->where('t.teacher_id', '=', $user_id)
	                    ->where('t.syear', '=', $syear)
	                    ->whereRaw($extra);
	            })
	            ->leftJoin('chapter_master AS cp', function ($join) {
	                $join->on('cp.subject_id', '=', 's.subject_id')
	                    ->on('cp.standard_id', '=', 's.standard_id');
	            })
	            ->leftJoin('content_master AS c', function ($join) use ($sub_institute_id) {
	                $join->on('c.subject_id', '=', 's.subject_id')
	                    ->on('c.standard_id', '=', 's.standard_id')
	                    ->on('c.sub_institute_id', '=', 's.sub_institute_id')
	                    ->where('c.sub_institute_id', '=', $sub_institute_id);
	            })
	            ->where('s.sub_institute_id', '=', $sub_institute_id)
				->where('s.subject_category','!=','SEL') // 17-12-2024 give sel rights teacher
	            ->groupBy('s.subject_id', 's.standard_id', 's.subject_category')
	            ->orderBy('s.sort_order')
	            ->get();
				// 17-12-2024 give sel rights teacher
				$getSEL = DB::table('sub_std_map as s')
	            ->selectRaw("STD.name AS standard_name,s.display_name AS subject_name,s.subject_id,STD.id AS standard_id,
	                s.display_image,GROUP_CONCAT(DISTINCT(CONCAT_WS('/',cp.chapter_name,cp.id))SEPARATOR '#') AS chapter_list,
	                IFNULL(s.subject_category,'SEL') AS content_category,s.sub_institute_id")
	            ->join('standard AS STD', 'STD.id', '=', 's.standard_id')
	            ->leftJoin('chapter_master AS cp', function ($join) {
	                $join->on('cp.subject_id', '=', 's.subject_id')
	                    ->on('cp.standard_id', '=', 's.standard_id');
	            })
	            ->leftJoin('content_master AS c', function ($join) use ($sub_institute_id) {
	                $join->on('c.subject_id', '=', 's.subject_id')
	                    ->on('c.standard_id', '=', 's.standard_id')
	                    ->on('c.sub_institute_id', '=', 's.sub_institute_id')
	                    ->whereIn('c.sub_institute_id',[1,$sub_institute_id]);
	            })
	            ->whereRaw($sub_institute_id_by_lms)
	            ->where('s.allow_content', '=', 'Yes')
				->where('s.subject_category','=','SEL') // 17-12-2024 give sel rights teacher
	            ->groupBy('s.subject_id', 's.standard_id', 's.subject_category')
	            ->orderBy('s.sort_order')
	            ->get()->toArray();
				// echo "<pre>";print_r($getSEL);exit;
				if (count($getSEL) > 0) {
					foreach ($getSEL as $key => $val) {
						$mycourse_arr[$val->content_category][] = (array)$val;
					}
				}

	    } else {
	        $arr = DB::table('sub_std_map as s')
	            ->selectRaw("STD.name AS standard_name,s.display_name AS subject_name,s.subject_id,STD.id AS standard_id,
	                s.display_image,GROUP_CONCAT(DISTINCT(CONCAT_WS('/',cp.chapter_name,cp.id))SEPARATOR '#') AS chapter_list,
	                IFNULL(s.subject_category,'My Course') AS content_category,s.sub_institute_id")
	            ->join('standard AS STD', 'STD.id', '=', 's.standard_id')
	            ->leftJoin('chapter_master AS cp', function ($join) {
	                $join->on('cp.subject_id', '=', 's.subject_id')
	                    ->on('cp.standard_id', '=', 's.standard_id');
	            })
	            ->leftJoin('content_master AS c', function ($join) use ($sub_institute_id) {
	                $join->on('c.subject_id', '=', 's.subject_id')
	                    ->on('c.standard_id', '=', 's.standard_id')
	                    ->on('c.sub_institute_id', '=', 's.sub_institute_id')
	                    ->where('c.sub_institute_id', '=', $sub_institute_id);
	            })
	            ->whereRaw($sub_institute_id_by_lms)
	            ->where('s.allow_content', '=', 'Yes')
	            // ->whereRaw($extra)
	            ->whereRaw(ltrim($extra, ' AND '))
	            ->groupBy('s.subject_id', 's.standard_id', 's.subject_category')
	            ->orderBy('s.sort_order')
	            ->get();
	    }

	    $arr = $arr->toArray();
	    if (count($arr) > 0) {
	        foreach ($arr as $key => $val) {
	            $mycourse_arr[$val->content_category][] = (array)$val;
	        }
	    }

	    $content_category = lmsContentCategoryModel::where('status', '1')
	        ->where(function ($query) use ($sub_institute_id) {
	            $query->where('sub_institute_id', '=', $sub_institute_id)
	                ->orWhere('sub_institute_id', '=', '0');
	        })
	        ->get()
	        ->toArray();

	    $data['content_category'] = $content_category;
	    $data['mycourse_arr'] = $mycourse_arr;

	    return $data;
	}
   
    public function course_search(Request $request)
    {
        $type = $request->input('type');
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $grade = $request->input('grade');
        $standard = $request->input('standard');

		// if($request->has('perm') && $request->perm==1){
		// 	$sub_institute_id[1] = 1;
		// }

        $mycourse_arr = [];
        $extra = "";
        
        if ($grade != "") {
            $extra .= " AND STD.grade_id = '".$grade."'";
        }
        if ($standard != "") {
            $extra .= " AND STD.id = '".$standard."'";
        }

        $arr = DB::select("SELECT STD.name AS standard_name,s.display_name AS subject_name,s.subject_id,STD.id AS standard_id,s.sub_institute_id,
                s.display_image,GROUP_CONCAT(DISTINCT(CONCAT_WS('/',cp.chapter_name,cp.id))) AS chapter_list,
                ifnull(s.subject_category,'My Course') AS content_category
                FROM sub_std_map s
                INNER JOIN standard STD ON STD.id = s.standard_id
                LEFT JOIN chapter_master cp ON cp.subject_id = s.subject_id
                LEFT JOIN content_master c ON c.subject_id = s.subject_id AND c.standard_id = s.standard_id AND c.sub_institute_id = s.sub_institute_id
                WHERE s.sub_institute_id = $sub_institute_id AND allow_content = 'Yes'
                 ".$extra." AND s.subject_category!='SEL'
                GROUP BY s.subject_id,s.standard_id,s.subject_category ORDER BY s.sort_order");

		$arr = json_decode(json_encode($arr), true);
				if (count($arr) > 0) {
					foreach ($arr as $key => $val) {
						$mycourse_arr[$val['content_category']][] = $val;
					}
				}

		$getSEL =	DB::select("SELECT STD.name AS standard_name,s.display_name AS subject_name,s.subject_id,STD.id AS standard_id,s.sub_institute_id,
                s.display_image,GROUP_CONCAT(DISTINCT(CONCAT_WS('/',cp.chapter_name,cp.id))) AS chapter_list,
                ifnull(s.subject_category,'My Course') AS content_category
                FROM sub_std_map s
                INNER JOIN standard STD ON STD.id = s.standard_id
                LEFT JOIN chapter_master cp ON cp.subject_id = s.subject_id
                LEFT JOIN content_master c ON c.subject_id = s.subject_id AND c.standard_id = s.standard_id AND c.sub_institute_id = s.sub_institute_id
                WHERE s.sub_institute_id IN (1,".$sub_institute_id.") AND allow_content = 'Yes'
                 AND s.subject_category='SEL'
                GROUP BY s.subject_id,s.standard_id,s.subject_category ORDER BY s.sort_order");

		$getSEL = json_decode(json_encode($getSEL), true);
			if (count($getSEL) > 0) {
				foreach ($getSEL as $key => $val) {
					$mycourse_arr[$val['content_category']][] = $val;
				}
			}
        //START Get Content Category
        $content_category = lmsContentCategoryModel::where('status', '1')->get()->toArray();
        //END Get Content Category

        $res['content_category'] = $content_category;
        $res['lms_subject'] = $mycourse_arr;
        $res['status_code'] = 1;
        $res['message'] = "SUCCESS";
        $res['grade'] = $grade;
        $res['standard'] = $standard;
		$res['sub_institute_id'] = $sub_institute_id;

        return is_mobile($type, 'lms/show_course', $res, "view");
    }


}

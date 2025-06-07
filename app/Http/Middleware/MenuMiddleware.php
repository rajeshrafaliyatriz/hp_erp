<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Route;
use App\Models\tblmenumasterModel;
use DB;

class MenuMiddleware
{
    protected Auth $auth;

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // return $next($request);
        $type = $request->input('type');

        if ($type == "API"  ||  $request->get('type') == "JSON") {
            return $next($request);
        } 

        $sub_institute_id = $request->session()->get('sub_institute_id');
        $client_id = session()->get('client_id');
        $is_admin = session()->get('is_admin');
        $user_id = $request->session()->get('user_id');
        $user_profile_id = $request->session()->get('user_profile_id');
        $user_profile_name = $request->session()->get('user_profile_name');

        if ($sub_institute_id == 0 && $is_admin == 1) {
            $rightsQuery = DB::table('tbluser as u')
                ->leftJoin('tblindividual_rights as i', function ($join) {
                    $join->whereRaw("u.id = i.user_id AND u.sub_institute_id = i.sub_institute_id");
                })->leftJoin('tblgroupwise_rights as g', function ($join) {
                    $join->whereRaw("u.user_profile_id = g.profile_id AND u.sub_institute_id = g.sub_institute_id");
                })->join('tblmenumaster as m', function ($join) use ($client_id) {
                    $join->whereRaw("(i.menu_id = m.id OR g.menu_id = m.id) AND FIND_IN_SET(".$client_id.", m.client_id)");
                })->selectRaw("GROUP_CONCAT(distinct m.id) AS MID")
                ->whereIn('u.sub_institute_id', explode(',', $sub_institute_id))
                ->where('u.status',1) // 23-04-24 by uma
                ->where('u.id', $user_id)->get()->toArray();
        } else {
            $rightsQuery = DB::table('tbluser as u')
                ->leftJoin('tblindividual_rights as i', function ($join) {
                    $join->whereRaw("u.id = i.user_id AND u.sub_institute_id = i.sub_institute_id AND u.user_profile_id=i.profile_id");
                })->leftJoin('tblgroupwise_rights as g', function ($join) {
                    $join->whereRaw("u.user_profile_id = g.profile_id AND u.sub_institute_id = g.sub_institute_id");
                })->join('tblmenumaster as m', function ($join) use ($sub_institute_id) {
                    $join->whereRaw("(i.menu_id = m.id OR g.menu_id = m.id) AND FIND_IN_SET(?, m.sub_institute_id)", [$sub_institute_id]);
                })->selectRaw("GROUP_CONCAT(distinct m.id) AS MID")
                ->whereIn('u.sub_institute_id', explode(',', $sub_institute_id))
                ->where(function ($q) use ($user_id) {
                    if (! session()->has('new_sub_institute_id')) {
                        $q->where('u.id', $user_id);
                    }
                })->get()->toArray();
        }

        $rightsQuery = array_map(function ($value) {
            return (array) $value;
        }, $rightsQuery);
        $rightsMenusIds = 0;

        if (isset($rightsQuery['0']['MID'])) {
            $rightsMenusIds = $rightsQuery['0']['MID'];
            if(substr($rightsMenusIds, -1)==","){
                $rightsMenusIds = substr($rightsMenusIds, 0,-1);
            }else{
                $rightsMenusIds = substr($rightsMenusIds, 0);
            }
        }
        // echo "<pre>";print_r($rightsMenusIds);exit;

        if ($type != "API"  &&  $type != "JSON") {
            if ($sub_institute_id == 0 && $is_admin == 1) {
                $data = tblmenumasterModel::where(['parent_id' => "0", 'level' => "1"])
                    ->whereRaw("find_in_set('$client_id',client_id) and status = 1 and id in (" . $rightsMenusIds . ")
                        ")->orderBy('sort_order')->get()->toArray();

                $subMenuData = tblmenumasterModel::where('parent_id', '!=', 0)
                    ->whereRaw("find_in_set('$client_id',client_id) AND level = 2 and id in (" . $rightsMenusIds . ")
                        and status = 1 and (menu_type != 'MASTER' or menu_type IS NULL)")->orderBy('sort_order')->get()->toArray();

                $i = 0;
                foreach ($subMenuData as $key => $value) {
                    $finalSubMenu[$value['parent_id']][$i] = $subMenuData[$key];
                    // if ($value['quick_menu'] != '') {
                    //     $quick_menu_data = DB::table('tblmenumaster')->whereRaw("find_in_set(id,(select quick_menu from
                    //         tblmenumaster where id = '" . $value['id'] . "'))")->get()->toArray();

                    //     $quick_menu_data = array_map(function ($value) {
                    //         return (array) $value;
                    //     }, $quick_menu_data);
                    //     $finalQuickMenu[$value['id']] = $quick_menu_data;
                    // }
                    $i++;
                }

                $subChildMenuData = tblmenumasterModel::where('parent_id', '!=', 0)
                    ->whereRaw("find_in_set('$client_id',client_id) AND level = 3 and id in (" . $rightsMenusIds . ")
                        and status = 1 and (menu_type != 'MASTER' or menu_type IS NULL)")->orderBy('sort_order')->get()->toArray();
                $i = 0;
                foreach ($subChildMenuData as $key => $value) {
                    $finalSubChildMenu[$value['parent_id']][$i] = $subChildMenuData[$key];
                    $i++;
                }
            } else {
                $data = tblmenumasterModel::where(['parent_id' => "0", 'level' => "1"])
                    ->whereRaw("find_in_set('$sub_institute_id',sub_institute_id) and status = 1
                        and id in (".$rightsMenusIds.") and (menu_type != 'MASTER' or menu_type IS NULL)")
                    ->orderBy('sort_order')->get()->toArray();

                $subMenuData = tblmenumasterModel::where('parent_id', '!=', 0)
                    ->whereRaw("find_in_set('$sub_institute_id',sub_institute_id) AND level = 2
                        and id in (" . $rightsMenusIds . ") and status = 1 and (menu_type != 'MASTER' or menu_type IS NULL) ")
                    ->orderBy('sort_order')->get()->toArray();

                $i = 0;
                foreach ($subMenuData as $key => $value) {
                    $finalSubMenu[$value['parent_id']][$i] = $subMenuData[$key];
                    // if ($value['quick_menu'] != '') {
                    //     $quick_menu_data = DB::table('tblmenumaster')->whereRaw("find_in_set(id,(select quick_menu from
                    //         tblmenumaster where id = '" . $value['id'] . "'))")->get()->toArray();

                    //     $quick_menu_data = array_map(function ($value) {
                    //         return (array) $value;
                    //     }, $quick_menu_data);
                    //     $finalQuickMenu[$value['id']] = $quick_menu_data;
                    // }
                    $i++;
                }

                $subChildMenuData = tblmenumasterModel::where('parent_id', '!=', 0)
                    ->whereRaw("find_in_set('$sub_institute_id',sub_institute_id) AND level = 3
                        and id in (" . $rightsMenusIds . ") and status = 1 and (menu_type != 'MASTER' or menu_type IS NULL )")->orderBy('sort_order')->get()->toArray();
                $i = 0;
                foreach ($subChildMenuData as $key => $value) {
                    $finalSubChildMenu[$value['parent_id']][$i] = $subChildMenuData[$key];
                    $i++;
                }
            }
            
            session()->put('menuMaster', $data);
            if (isset($finalSubMenu)) {
                session()->put('submenuMaster', $finalSubMenu);
            }   
            if (isset($finalQuickMenu)) {
                session()->put('quickmenuMaster', $finalQuickMenu);
            }
            if (isset($finalSubChildMenu)) {
                session()->put('subChildmenuMaster', $finalSubChildMenu);
            }
        }

        return $next($request);
    }
}

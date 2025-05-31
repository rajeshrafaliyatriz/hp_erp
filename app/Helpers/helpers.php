<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

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

    if (!function_exists('getDataWithId')) {
        function getDataWithId($id,$type){
            $name='-';
            if($type=="department"){
                $name = DB::table('hrms_departments')->where('id',$id)->value('department');
            }
            elseif($type=="employee"){
                $name = DB::table('tbluser')->where('id',$id)->selectRaw('CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name')->value('name');
            }
            elseif($type=="student"){
                $stuData = DB::table('tblstudent')->where('id',$id)->selectRaw('*,CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name')->first();
                $name = $stuData->name.' ('.$stuData->enrollment_no.')';
            }
           return $name;
       }
    }
}

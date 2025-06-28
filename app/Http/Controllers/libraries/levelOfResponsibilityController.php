<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\libraries\SLevelResponsibility;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class levelOfResponsibilityController extends Controller
{
   public function index(Request $request)
    {
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $detailsLevel = SLevelResponsibility::get()->toArray();
        $allLevels = $attrData = [];
        foreach ($detailsLevel as $key => $value) {
           $allLevels[$value['level']] = $value;
           if($value['attribute_type']!='Business skills/Behavioural factors'){
            $attrData[$value['level']][$value['attribute_type']][$value['attribute_name']] = $value;
           }else{
            $attrData[$value['level']]['Business_skills'][$value['attribute_name']] = $value;
           }
        }
        $res['levelsData'] = array_values($allLevels);
        $res['attrData'] = $attrData;
        $res['allData'] = $detailsLevel;
        return is_mobile($type, 'level_of_responsibility.index', $res, 'redirect');
    }

}

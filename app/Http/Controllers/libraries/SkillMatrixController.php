<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\skill\skill;
use App\Models\skill\matrix;
use App\Models\skill\AssessmentLibrary;
use App\Models\lms\counselling\OnetCareerCluster;
use Illuminate\Support\Facades\Auth;
use GenTux\Jwt\GetsJwtToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use function App\Helpers\is_mobile;
use App\Models\libraries\SLibraryMap;

class SkillMatrixController extends Controller
{
    public function index(Request $request)
    {
        $user_id = $request->session()->get('user_id');
        $skills = skill::all();
        $completedCount = matrix::where('user_id', $user_id)->count();
        $totalSkills = $skills->count();
        $progress = $totalSkills > 0 ? round(($completedCount / $totalSkills) * 100) : 0;

        return view('skill.matrix.index', compact('skills', 'progress', 'completedCount', 'totalSkills'));
    }
    
    private function validateSkillData($skills)
    {
        $rules = [];
        foreach ($skills as $index => $skill) {
            $rules["skills.{$index}.skill_id"] = 'required|exists:s_users_skills,id';
            $rules["skills.{$index}.skill_level"] = 'required|integer|min:1|max:5';
            $rules["skills.{$index}.type"] = 'nullable|string';

            // Change from 'array' to allow objects/strings that can be JSON encoded
            $rules["skills.{$index}.knowledge"] = 'nullable';
            $rules["skills.{$index}.ability"] = 'nullable';
            $rules["skills.{$index}.behaviour"] = 'nullable';
            $rules["skills.{$index}.attitude"] = 'nullable';
        }
        
        return Validator::make(['skills' => $skills], $rules);
    }

    public function storeBulk(Request $request)
    {
        try {
            DB::beginTransaction();
            
            $user_id = (int)$request->user_id;
            $skills = $request->skills;

            $validator = $this->validateSkillData($skills);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            foreach ($skills as $skill) {
                $addUpdate = [
                    'skill_level' => (int)$skill['skill_level'],
                    'user_id' => $user_id,
                    'skill_id' => (int)$skill['skill_id'],
                    'type' => $skill['type'] ?? null
                ];

                // Handle additional attributes - accept objects and convert to JSON
                $attributes = ['knowledge', 'ability', 'behaviour', 'attitude'];
                foreach ($attributes as $attribute) {
                    if (isset($skill[$attribute]) && !empty($skill[$attribute])) {
                        $addUpdate[$attribute] = json_encode($skill[$attribute]);
                    }
                }

                matrix::updateOrCreate(
                    ['user_id' => $user_id, 'skill_id' => $skill['skill_id']],
                    $addUpdate
                );                
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Skills updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating skills: ' . $e->getMessage()
            ], 500);
        }
    }

    public function JobRole()
    {
        $skills = DB::table('s_jobrole')
        ->select(
            'id',
            'industries',
            'sector',
            'track',
            'jobrole',
            'description',
            DB::raw('FLOOR(RAND() * 9) + 7 AS Skill'),
            DB::raw('FLOOR(RAND() * 9) + 5 AS Knowledge'),
            DB::raw('FLOOR(RAND() * 20) + 8 AS Ability'),
            DB::raw('FLOOR(RAND() * 4) + 9 AS Tasks')
        )
        ->where('status', 'Active')
        ->orderBy('id') // Ordering by ID
        ->get()
        ->map(function ($skill) {
            $skill->SkillData = '<div class="card info-card p-4">
                <span class="badge bg-primary">➤ Technical Skills</span>
                    <ul>
                        <li>➤ Nursing Productivity and Innovation <span class="badge bg-danger">Level 5</span></li>
                        <li>➤ Medication Management in Nursing</li>
                        <li>➤ Patient Care Delivery in Nursing <span class="badge bg-danger">Level 4</span></li>
                        <li>➤ Respiratory Care in Nursing</li>
                    </ul>

                    <span class="badge bg-primary">➤ Functional Skills</span>
                    <ul>
                        <li>➤ Learning Needs Analysis <span class="badge bg-danger">Level 3</span></li>
                        <li>➤ Nursing Research and Statistics</li>
                    </ul>

                    <span class="badge bg-primary">➤ Soft Skills (Behavioral & Interpersonal Skills)</span>
                    <ul>
                        <li>➤ Inter-professional Collaboration</li>
                        <li>➤ Effective Communication in Nursing</li>
                    </ul>

                    <span class="badge bg-primary">➤ Cognitive & Thinking Skills</span>
                    <ul>
                        <li>➤ Sense Making</li>
                        <li>➤ Decision Making</li>
                        <li>➤ Transdisciplinary Thinking</li>
                    </ul>

                    <span class="badge bg-primary">➤ Leadership & Management Skills</span>
                    <ul>
                        <li>➤ Change Management</li>
                        <li>➤ Clinical Teaching and Supervision</li>
                        <li>➤ Emergency Response and Crisis Management</li>
                        <li>➤ Department Financial Management</li>
                        <li>➤ Health Education Programme Development and Implementation</li>
                        <li>➤ Performance Management for Nursing</li>
                        <li>➤ Quality Improvement and Safe Practices</li>
                        <li>➤ Service Quality Management</li>
                        <li>➤ Clinical Services Development</li>
                        <li>➤ Strategy Management</li>
                        <li>➤ Developing People</li>
                    </ul>

                    <span class="badge bg-primary">➤ Compliance & Regulatory Skills</span>
                    <ul>
                        <li>➤ Clinical Governance</li>
                        <li>➤ Infection Prevention and Control in Nursing Practice</li>
                        <li>➤ Workplace Safety and Health</li>
                    </ul>
                <div class="progress mt-2">
                    <div class="progress-bar bg-primary" style="width: 60%"></div>
                </div>
            </div>';
            return $skill;
        })
        ->map(function ($tasks) {
            $tasks->TasksData = '<div class="card info-card p-4">
                <span class="badge bg-danger">➤ Advance nursing practices</span>
                <ul>
                    <li>➤ Establish funding models for new care models or nursing clinical services</li>
                    <li>➤ Implement new practices and care models or services in accordance to frameworks that include considerations for facilities, resourcing, funding, training, processes, outcomes and regulations</li>
                    <li>➤ Measure outcomes of advanced or specialised nursing practices to assess and improve new interventions</li>
                </ul>
                <span class="badge bg-danger">➤ Oversee nursing clinical care delivery</span>
                <ul>
                    <li>➤ Establish frameworks for hospital-to-community models of care that include funding, manpower, pilots, outcome measurements and implementation</li>
                    <li>➤ Oversee nursing practices and care delivery outcomes</li>
                    <li>➤ Establish frameworks for evidence-based nursing</li>
                    <li>➤ Develop strategies to empower and engage patients and caregivers</li>
                    <li>➤ Promote inter-professional collaboration in care delivery</li>
                </ul>
                <span class="badge bg-danger">➤ Drive nursing quality and patient safety</span>
                <ul>
                    <li>➤ Lead multi-disciplinary work groups to improve patient and staff safety</li>
                    <li>➤ Establish frameworks for critical communications</li>
                    <li>➤ Manage adverse events according to organisational frameworks</li>
                    <li>➤ Establish an open culture to facilitate quality and patient safety development</li>
                    <li>➤ Establish nursing infection prevention and control policies and procedures</li>
                    <li>➤ Lead nursing to achieve local and international accreditations</li>
                    <li>➤ Guide nursing clinical audits</li>
                    <li>➤ Adopt new technology and electronic tools and devices for better quality and patient safety outcomes</li>
                </ul>
                <div class="progress mt-2">
                    <div class="progress-bar bg-danger" style="width: 65%"></div>
                </div>
            </div>';
            return $tasks;
        })
        ->map(function ($knowledge) {
            $knowledge->KnowledgeData = '<div class="card info-card p-4">
                    <ul>
                        <li>➤ Innovation evidence, legal and intellectual property rights</li>
                        <li>➤ Clinical information technology analysis frameworks</li>
                        <li>➤ Best practices on the selection and implementation of technology to drive improved quality of care and operational efficiencies</li>
                        <li>➤ Ethical and social issues in nursing informatics and consumer informatics</li>
                        <li>➤ Informatics applications for telehealth, consumer health and community-based care</li>
                        <li>➤ Metrics to measure effectiveness of new technologies in the delivery of patient care</li>
                        <li>➤ Manpower optimisation through adoption of new technologies</li>
                    </ul>
                </div>';
            return $knowledge;
        })
        ->map(function ($ability) {
            $ability->AbilityData = '<div class="card info-card p-4">
                <ul>
                    <li>➤ Promote best practices to adopt new technology towards quality improvement and increase productivity</li>
                    <li>➤ Mitigate challenges in the adoption of new technology</li>
                    <li>➤ Facilitate transition of care in the use of new nursing informatics and medical scientific technology</li>
                    <li>➤ Evaluate data integrity with the adoption of new technologies</li>
                    <li>➤ Develop work plans for the implementation of nursing informatics and medical scientific technology by analysing the practicality, feasibility and risks of new technology adoption</li>
                </ul>
            </div>';
            return $ability;
        });

        return view('skill.jobrole.index', compact('skills'));
    }

    public function JobDescription(Request $request)
    {
        $career = DB::table('s_jobrole')
        ->select('id','sector','track','jobrole','description')
        ->where('id', $request->query('id'))
        ->first();

        // Pass the career data to the view
        return view('skill.jobrole.jobdescription', compact('career'));
    }

    public function AssessmentLibrary()
    {
        $assessments = AssessmentLibrary::all();
        //$assessments = AssessmentLibrary::inRandomOrder()->get();
        return view('skill.assessment.assessment_library', compact('assessments'));
    }
	
	public function getKaba(Request $request)
	{
	    $sub_institute_id = $request->sub_institute_id;
	    $type = $request->type;
	    $typeId = $request->type_id;
	    $titleSearch = $request->title;

	    // -----------------------------------
	    // 1. Detect Model According to Type
	    // -----------------------------------
	    $models = [
	        'jobrole'   => \App\Models\libraries\userJobroleModel::class,
            'task'      => \App\Models\libraries\userJobroleTask::class,
	        'knowledge' => \App\Models\libraries\KabaMaster::class,
	        'ability'   => \App\Models\libraries\KabaMaster::class,
	        'attitude'  => \App\Models\libraries\KabaMaster::class,
	        'behaviour' => \App\Models\libraries\KabaMaster::class,
	    ];

	    if (!isset($models[$type])) {
	        return response()->json(['error' => 'Invalid type'], 400);
	    }

	    $mainModel = $models[$type];

	    // -----------------------------------
	    // 2. Find by Title if Provided
	    // -----------------------------------
	    if ($titleSearch) {
	        $items = $mainModel::where('sub_institute_id', $sub_institute_id)->where('jobrole', $titleSearch)->first();
	        if (!$items) return response()->json(['error' => 'Title not found'], 404);
	        $typeId = $items->id;
	    }

	    // -----------------------------------
	    // 3. Get Mapping
	    // -----------------------------------
	    $map = SLibraryMap::where('type', $type)
	        ->where('type_id', $typeId)
	        ->where('sub_institute_id', $sub_institute_id)
	        ->first();

	    if (!$map) {
	        return response()->json(['error' => 'Mapping not found'], 404);
	    }

	    // -----------------------------------
	    // 4. Fetch Basic Item Info
	    // -----------------------------------
	    //dd(\App\Models\libraries\userJobroleTask::find(61041));
        $items = $mainModel::find($typeId);

	    // -----------------------------------
	    // 5. Helper to load full KABA objects
	    // -----------------------------------
	    function loadKaba($ids, $type)
		{
		    if (!$ids) return [];

		    $idList = array_filter(explode(',', $ids));

		    // Use ONE MODEL but dynamic table
		    $model = \App\Models\libraries\KabaMaster::fromType($type);

		    return $model->whereIn('id', $idList)->get()->map(function ($item) {
		        return array_filter([
		            'id'           => $item->id,
		            'category'     => $item->category,
		            'sub_category' => $item->sub_category,
		            'title'        => $item->title,
		            'description'  => $item->description,
		        ], function ($v) {
		            return !is_null($v) && $v !== "";
		        });
		    })->toArray();
		}

	    // -----------------------------------
	    // 6. Build Response
	    // -----------------------------------
        function getDisplayName($items)
        {
            return $items->task
                ?? $items->title
                ?? $items->jobrole
                ?? '-';
        }

	    $response = [
	        'type'        => $map->type,
	        'type_id'     => $map->type_id,
	        'title'       => getDisplayName($items),
	        'description' => $items->description ?? null,

            // Only add if included in type
	        'task'        => $type === 'task'   ? loadKaba($map->task_ids,'task')   : null,
	        'skill'       => loadKaba($map->skill_ids,'skill'),
	        'jobrole'     => $type === 'jobrole'? loadKaba($map->jobrole_ids,'jobrole'): null,

	        // Auto convert IDs → full data
	        'knowledge'   => loadKaba($map->knowledge_ids,'knowledge'),
	        'ability'     => loadKaba($map->ability_ids,'ability'),
	        'attitude'    => loadKaba($map->attitude_ids,'attitude'),
	        'behaviour'   => loadKaba($map->behaviour_ids,'behaviour'),
	    ];

	    // Remove null or empty fields
	    $response = array_filter($response, function ($v) {
	        return !is_null($v) && $v !== [] && $v !== "";
	    });

	    return response()->json($response, 200);
	}

}

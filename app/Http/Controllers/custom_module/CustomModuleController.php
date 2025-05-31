<?php

namespace App\Http\Controllers\custom_module;

use App\Http\Controllers\Controller;
use App\Models\CustomModuleTable;
use App\Models\CustomModuleTableColumn;
use App\Models\DynamicModel;
use App\Models\school_setup\academic_sectionModel;
use App\Models\school_setup\divisionModel;
use App\Models\school_setup\standardModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Opcodes\LogViewer\Log;
use function App\Helpers\is_mobile;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class CustomModuleController extends Controller
{
    public function tables(Request $request)
    {
        $subInstituteId = $request->session()->get('sub_institute_id');
        $tables = CustomModuleTable::where('sub_institute_id', $subInstituteId)->get();
        $data = ['data' => $tables->map(function ($table) {
            $tableExists = DB::select("SHOW TABLES LIKE '{$table['table_name']}'");
            $table['is_exists'] = count($tableExists);
            return $table;
        })];
        $type = $request->input('type');
        return is_mobile($type, "custom_modules.tables.index", $data, "view");
    }

    public function tableCreate(Request $request, $id = 0)
    {
        $type = $request->input('type');
        $customModuleTable = $id ? CustomModuleTable::with('whereColumns')->find($id) : new CustomModuleTable();
        $customModuleTable['table_name'] = $customModuleTable['table_name'] ?? '';
        $customModuleTable['module_name'] = $customModuleTable['module_name'] ?? '';
        $customModuleTable['module_type'] = $customModuleTable['module_type'] ?? '';
        $customModuleTable['display_under'] = $customModuleTable['display_under'] ?? '';
        $customModuleTable['migration'] = $customModuleTable['migration'] ?? '';
        $customModuleTable['seeder'] = $customModuleTable['seeder'] ?? '';
        $customModuleTable['model'] = $customModuleTable['model'] ?? '';
        $customModuleTable['controller'] = $customModuleTable['controller'] ?? '';
        $customModuleTable['route'] = $customModuleTable['route'] ?? '';
        $customModuleTable['view'] = $customModuleTable['view'] ?? '';
        $customModuleTable['storage'] = $customModuleTable['storage'] ?? '';
        $customModuleTable['validation'] = $customModuleTable['validation'] ?? '';
        $customModuleTable['access_link'] = $customModuleTable['access_link'] ?? '';
        $customModuleTable['id'] = $id;
        $customModuleTable['level_2'] = $customModuleTable['level_2'] ?? '';
        $customModuleTable['helper_function'] = $customModuleTable['helper_function'] ?? '';
        $customModuleTable['syear_wise'] = $customModuleTable['syear_wise'] ?? '';
        $customModuleTable['helperFunctions'] = ['Grade,Standard,Division','Grade,Standard,Division,Student', 'Grade,Standard','Grade,Standard,Student', 'Term,Grade,Standard,Division', 'Department,Employee'];
        $customModuleTable['DisplayUnder'] = '';
        if ($id) {
            $customModuleTable['tableCreated'] = Schema::hasTable($customModuleTable['table_name']) ? 1 : 0;
        }

        return is_mobile($type, "custom_modules.tables.create-edit", $customModuleTable, "view", 'compact');
    }

    public function tableStore(Request $request)
    {
        // echo "<pre>";print_r(session()->all());exit;
        $subInstituteId = $request->session()->get('sub_institute_id');
        $type = $request->input('type');
        $request->validate([
            'module_name' => 'required',
            'module_type' => 'required',
            'display_under' => 'required',
            'table_name' => 'required|string|unique:custom_module_tables,table_name,' . $request->id,
        ]);
// echo "<pre>";print_r($subInstituteId);exit;
        if ($request->id > 0) {
            $customModuleTable = CustomModuleTable::find($request->id);
        } else {
            $customModuleTable = new CustomModuleTable();
        }
        $prefixTableName = str_replace(' ','_',$request->table_name);
        if (!Str::startsWith($request->table_name, "Z_")) {
            $prefixTableName = "Z_" . str_replace(' ','_',$request->table_name);
        }

        $customModuleTable->module_name = $request->module_name;
        $customModuleTable->module_type = $request->module_type;
        $customModuleTable->display_under = $request->display_under;
        $customModuleTable->migration = $request->migration;
        $customModuleTable->seeder = $request->seeder;
        $customModuleTable->model = $request->model;
        $customModuleTable->controller = $request->controller;
        $customModuleTable->route = $request->route;
        $customModuleTable->view = $request->view;
        $customModuleTable->storage = $request->storage;
        $customModuleTable->validation = $request->validation;
        $customModuleTable->access_link = $request->has($request->access_link) ? str_replace(' ','_',$request->access_link) : $request->access_link;
        $customModuleTable->table_name = $prefixTableName;
        $customModuleTable->sub_institute_id = $subInstituteId;
        // added by uma start 22-04-2025
        $customModuleTable->level_2 = $request->level_2 ?? null;
        $customModuleTable->helper_function = $request->helper_function ?? null;
        $customModuleTable->syear_wise = $request->syear_wise ?? null;
        $customModuleTable->relational_table = $request->relational_table ?? '';

        // added by uma end 22-04-2025
        $customModuleTable->save();
// echo "<pre>";print_r($customModuleTable);exit;

        // $res added by uma on 24-03-2025
        

        if(isset($request->syear_wise) && $request->syear_wise==1){
            DB::table('custom_module_table_columns')->where('table_id',$customModuleTable->id)->where('column_name','syear')->delete();
            DB::table('custom_module_table_columns')->insert([
                "column_name" => 'syear',
                "table_id" => $customModuleTable->id,
                "auto_increment" => 0, 
                "type" => 'bigint',
                "length" => 0, // Length is not applicable for bigInteger
                'not_null' => 0,
                'index' => null,
                'default' => null,
                'created_at'=>now()
                ]);
        }
// end 22-04-2025
      
        // added on 25-03-2025 by uma for student and employee columns start
        $studentColumns = ['first_name','middle_name','last_name','enrollment_no','mobile','mother_mobile','email','academic_section','Standard','Division','roll_no'];
        $employeeColumns = ['first_name','middle_name','last_name','staff_mobile','email'];

        foreach ($studentColumns as $key => $colName) {
          DB::table('custom_module_table_columns')->where('table_id',$customModuleTable->id)->where('column_name',$colName)->delete();
           if($request->has('student')){
            DB::table('custom_module_table_columns')->insert([
                "column_name" => $colName,
                    "table_id" => $customModuleTable->id,
                    "auto_increment" => 0, 
                    "type" => 'varchar',
                    "length" => 255,
                    'not_null' => 0,
                    'index' => null,
                    'default' => null,
                    'created_at'=>now()
                ]);
           }
        }

        foreach ($employeeColumns as $key => $colName) {
            DB::table('custom_module_table_columns')->where('table_id',$customModuleTable->id)->where('column_name',$colName)->delete();
            if($request->has('staff')){
                DB::table('custom_module_table_columns')->insert([
                        "column_name" => $colName,
                        "table_id" => $customModuleTable->id,
                        "auto_increment" => 0, 
                        "type" => 'varchar',
                        "length" => 255,
                        'not_null' => 0,
                        'index' => null,
                        'default' => null,
                        'created_at'=>now()
                    ]);
            }
        }
        
        // added on 25-03-2025 by uma end 
            $tableColumn='';
        if (isset($request->division)) {
            $tableColumn = $tableColumn->where('column_name', 'Division')->first();
            if (!$tableColumn) {
                $tableColumn = new CustomModuleTableColumn();
                $tableColumn->column_name = 'Division';
                $tableColumn->table_id = $customModuleTable->id;
                $tableColumn->auto_increment = 0;
                $tableColumn->type = 'integer';
                $tableColumn->length = "255";
                $tableColumn->not_null = 0;
                $tableColumn->index = null;
                $tableColumn->default = null;
                $tableColumn->save();
            }
        } 
        
        if (isset($request->standard)) {
            $tableColumn = $tableColumn->where('column_name', 'Standard')->first();
            if (!$tableColumn) {
                $tableColumn = new CustomModuleTableColumn();
                $tableColumn->column_name = 'Standard';
                $tableColumn->table_id = $customModuleTable->id;
                $tableColumn->auto_increment = 0;
                $tableColumn->type = 'integer';
                $tableColumn->length = "255";
                $tableColumn->not_null = 0;
                $tableColumn->index = null;
                $tableColumn->default = null;
                $tableColumn->save();
            }
        } 
        // $res added by uma on 24-03-2025
        if($tableColumn){
            $res['status']= 1;
            $res['message']='Added Successfully';
        }
        else{
            $res['status']= 0;
            $res['message']='Failed to Add Data';
        }
        // $res added by uma on 24-03-2025
        return is_mobile($type, "custom-module.tables", $res, "redirect");
    }

    public function tableDelete(Request $request, $id)
    {
        $type = $request->input('type');
        $i=0;
        if ($id > 0) {
            $i=1;
            $table = CustomModuleTable::find($id);
            // check in menumaster 09-04-2025
            $accessLink = (isset($table->access_link) && $table->access_link!='') ? $table->access_link : str_replace('_',' ',$table->module_name).'.index';

            if (!empty($table)) {
                DB::statement('DROP TABLE IF EXISTS ' . $table->table_name);
            }
            CustomModuleTable::where('id', $id)->delete();
        }
        // $res added by uma on 24-03-2025
        if($i>0){
            $res['status']= 1;
            $res['message']='Table Deleted Successfully';
        }
        else{
            $res['status']= 0;
            $res['message']='Failed to Delete Table';
        }

        return is_mobile($type, "custom-module.tables", $res, "redirect");
    }

    public function tableColumnCreate(Request $request, $id, $colId = 0)
    {
        $data['column_name'] = '';
        $data['column_length'] = 0;
        $data['column_type'] = '';
        $data['column_not_null'] = 0;
        $data['column_index'] = '';
        $data['column_default'] = '';
        $data['column_auto_increment'] = 0;
        $data['field_type'] = 'text-field';
        $data['field_value'] = '';
        $data['column_id'] = 0;
        if ($colId) {
            $findColumnData = CustomModuleTableColumn::find($colId);
            $data['column_name'] = $findColumnData['column_name'];
            $data['column_length'] = $findColumnData['length'];
            $data['column_type'] = $findColumnData['type'];
            $data['column_not_null'] = $findColumnData['not_null'];
            $data['column_index'] = $findColumnData['index'];
            $data['column_default'] = $findColumnData['default'];
            $data['column_auto_increment'] = $findColumnData['auto_increment'];
            $data['field_type'] = $findColumnData['field_type'];
            $data['field_value'] = implode(',', json_decode($findColumnData['field_value']));
            $data['column_id'] = $colId;
        }
        $data['data'] = CustomModuleTable::with('columns')->whereId($id)->first();
        // echo "<pre>";print_r($data);exit;
        $type = $request->input('type');
        return is_mobile($type, "custom_modules.tables.columns.index", $data, "view");
    }

    public function tableColumnStore(Request $request, $id)
    {
        $type = $request->input('type');
        $sub_institute_id =session()->get('sub_institute_id');
        $user_id =session()->get('user_id');
        $user_profile_id =session()->get('user_profile_id');
        if(in_array($type,['API','JSON'])){
            $sub_institute_id =$request->get('sub_institute_id');
            $user_id =$request->get('user_id');
            $user_profile_id =$request->get('user_profile_id');
        }
        // update route,icon,parent in tblmenumnaster 23-04-2025

        $request->validate([
            'column_name' => [
                'required',
                Rule::unique('custom_module_table_columns')->where(function ($query) use ($id) {
                    return $query->where('table_id', $id);
                })->ignore($request->col_id)
            ],
        ]);
        if ($request->col_id) {
            $tableColumn = CustomModuleTableColumn::find($request->col_id);
        } else {
            $tableColumn = new CustomModuleTableColumn();
        }

        $tableColumn->column_name = Str::snake($request->column_name);
        $tableColumn->table_id = $id;
        $tableColumn->auto_increment = $request->has('column_auto_increment') ? 1 : 0;
        $tableColumn->type = $request->column_type;
        $tableColumn->length = $request->column_length;
        $tableColumn->not_null = $request->has('column_not_null') ? 1 : 0;
        $tableColumn->index = $request->column_index;
        $tableColumn->default = $request->column_default;
        $tableColumn->field_type = $request->field_type;
        $tableColumn->field_value = json_encode(explode(',', $request->field_value));
        $tableColumn->save();
        
        if($tableColumn){
            $res['status']= 1;
            $res['message']='Added Successfully';
        }
        else{
            $res['status']= 0;
            $res['message']='Failed to Add Data';
        }
        
        // $res added by uma on 24-03-2025
        // return is_mobile($type, ["route" => "custom_module_table_column.create", "id" => $id], $res, "redirect", '', 1);
        return is_mobile($type, "/custom-module/table-column-create/".$id, $res, "route_with_id");
    }

    public function tableColumnDelete(Request $request, $id, $colId)
    {
        // echo "<pre>";print_r($id);exit;
        $type = $request->input('type');
        $i = 0;
        if ($id > 0) {
            $i=1;
            $findData = CustomModuleTableColumn::find($colId);

            if ($findData) {
                $findData->delete();
            }

        }
         // $res added by uma on 24-03-2025
         if($i!=0){
            $res['status']= 1;
            $res['message']='Deleted Successfully';
        }
        else{
            $res['status']= 0;
            $res['message']='Failed to Add Data';
        }
        // $res added by uma on 24-03-2025
        // return is_mobile($type, ["route" => "custom_module_table_column.create", "id" => $id], null, "redirect", '', 1);
        return is_mobile($type, "/custom-module/table-column-create/".$id, $res, "route_with_id");
    }

    public function createDBTable(Request $request, $id)
    {
        $type = $request->input('type');
        $sub_institute_id =session()->get('sub_institute_id');
        $user_id =session()->get('user_id');
        $user_profile_id =session()->get('user_profile_id');
        if(in_array($type,['API','JSON'])){
            $sub_institute_id =$request->get('sub_institute_id');
            $user_id =$request->get('user_id');
            $user_profile_id =$request->get('user_profile_id');
        }
       
         $tableData = CustomModuleTable::find($id);
        
         // menu insert ends here 09-04-2025
        $getTableData = CustomModuleTable::with('columns')->whereId($id)->first();
        if ($getTableData) {
            if (!count($getTableData['columns'])) {
                // return is_mobile($type, ["route" => "custom_module_table_column.create", "id" => $id], ['message' => 'Please add at least one column'], "redirect", '', 1);
                 // $res added by uma on 24-03-2025
                
                    $res['status']= 0;
                    $res['message']='please add at least one columns';
                
                return is_mobile($type, "/custom-module/table-column-create/".$id, $res, "route_with_id");
            }
        }
        $tableName = $getTableData['table_name'];
        $tableExists = DB::select("SHOW TABLES LIKE '{$tableName}'");

        if (!empty($tableExists)) {
            // Fetch existing columns from the table
            $existingColumns = DB::select("SHOW COLUMNS FROM {$tableName}");
            $existingColumnNames = array_column($existingColumns, 'Field');
            
            // Exclude certain columns from being modified/dropped
            $excludedColumns = ['id', 'sub_institute_id', 'created_at', 'updated_at'];
            
            $newColumns = [];
            $modifyColumns = [];
            $columnsToDrop = array_diff($existingColumnNames, $excludedColumns);
            
            // Iterate over provided columns
            foreach ($getTableData['columns'] as $column) {
                $columnName = $column['column_name'];
                $columnType = strtoupper($column['type']);
                $columnLength = !empty($column['length']) ? "({$column['length']})" : ($columnType == 'VARCHAR' ? "(255)" : "");
                $autoIncrement = !empty($column['auto_increment']) ? "AUTO_INCREMENT" : "";
                $notNull = !empty($column['not_null']) ? "NOT NULL" : "";
                $defaultValue = isset($column['default']) ? "DEFAULT '{$column['default']}'" : "";
            
                $columnDefinition = "{$columnName} {$columnType}{$columnLength} {$notNull} {$defaultValue} {$autoIncrement}";
            
                if (!in_array($columnName, $excludedColumns)) {
                    if (!in_array($columnName, $existingColumnNames)) {
                        // If column does not exist, add it
                        $newColumns[] = "ADD COLUMN {$columnDefinition}";
                    } else {
                        // If column exists, modify it
                        $modifyColumns[] = "MODIFY COLUMN {$columnDefinition}";
                    }
            
                    // Remove from drop list since it's present in the new schema
                    if (($key = array_search($columnName, $columnsToDrop)) !== false) {
                        unset($columnsToDrop[$key]);
                    }
                }
            }   
            $update = 0;
            // Execute ALTER TABLE queries if needed
            if (!empty($newColumns) || !empty($modifyColumns)) {
                $update = 1;
                $alterQueries = array_merge($newColumns, $modifyColumns);
                $alterTableSql = "ALTER TABLE {$tableName} " . implode(", ", $alterQueries) . ";";
                DB::statement($alterTableSql);
            }
            
            // Drop columns that are no longer needed
            if (!empty($columnsToDrop)) {
                $update = 1;
                $dropColumns = array_map(fn($column) => "DROP COLUMN {$column}", $columnsToDrop);
                $alterTableSql = "ALTER TABLE {$tableName} " . implode(", ", $dropColumns) . ";";
                DB::statement($alterTableSql);
            }
            
            // return is_mobile($type, ["route" => "custom_module_table_column.create", "id" => $id], ['message' => "Table '{$tableName}' has been updated successfully."], "redirect", ['message' => "Table '{$tableName}' has been updated successfully."], 1);

            // return "Table '{$tableName}' has been updated successfully.";
             // $res added by uma on 24-03-2025
           
            
             if($update==1){
                $res['status']= 1;
                $res['message']='Updated Table Successfully';
            }
            else{
                $res['status']= 0;
                $res['message']='Failed to Update Table';
            }
            return is_mobile($type, "/custom-module/table-column-create/".$id, $res, "route_with_id");
        } else {
            // Prepare the column definitions for creating the table
            $prepareColumn = [];
            $primaryKey = '';

            foreach ($getTableData['columns'] as $column) {
                $columnDefinition = $column['column_name'] . " " . $column['type'];

                if (isset($column['length'])) {
                    $columnDefinition .= $column['length'] > 0 ? " ({$column['length']})" : ($column['type'] == 'varchar' ? "(255)" : " ");
                }

                if ($column['auto_increment'] == 1) {
                    $columnDefinition .= " AUTO_INCREMENT";
                    $primaryKey = "PRIMARY KEY ({$column['column_name']})";
                }

                if ($column['not_null'] == 1) {
                    $columnDefinition .= " NOT NULL";
                }

                if (isset($column['default'])) {
                    $columnDefinition .= " DEFAULT '{$column['default']}'";
                }

                $prepareColumn[] = $columnDefinition;
            }

            if ($primaryKey) {
                $prepareColumn[] = $primaryKey;
            }
            $res['status']= 0;
            $res['message']='Failed Create Table';
            $columns = implode(",\n", $prepareColumn);
            // Create table if it doesn't exist
            $i=0;
            try {
                $i=1;
                // $created = DB::statement("
                //     CREATE TABLE {$tableName} (
                //         id BIGINT NOT NULL AUTO_INCREMENT,
                //         " . rtrim($columns, ',') . ",
                //         sub_institute_id INT NOT NULL DEFAULT '0',
                //         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                //         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                //         PRIMARY KEY (id)
                //     ) ENGINE=INNODB;
                // "); // commented on 09-04-2025
               $created = DB::statement("
                    CREATE TABLE {$tableName} (
                        id BIGINT NOT NULL AUTO_INCREMENT,
                        " . rtrim($columns, ',') . ",
                        sub_institute_id INT NOT NULL DEFAULT 0,
                        created_by BIGINT DEFAULT NULL,
                        updated_by BIGINT DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL,
                        PRIMARY KEY (id)
                    ) ENGINE=INNODB;
                ");
            
                if($created){
                    $res['status']= 1;
                    $res['message']='Table Created Successfully';
                }
    
            } catch (\Exception $e) {
                $res['status']= 0;
                $res['message']=$e->getMessage();
            }
            
            // DB::statement($sql);

            // return is_mobile($type, ["route" => "custom_module_table_column.create", "id" => $id], ['message' => "Table '{$tableName}' has been created successfully."], "redirect", ['message' => "Table '{$tableName}' has been created successfully."], 1);
            // $res added by uma on 24-03-2025
           
            return is_mobile($type, "/custom-module/table-column-create/".$id, $res, "route_with_id");
        }

// sub_institute_id int NOT NULL DEFAULT '0',
    }

    public function crudIndex(Request $request, $id)
    {
        $type = $request->input('type');
        $subInstituteId = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');

        $data['data'] = CustomModuleTable::with('columns')->where([['sub_institute_id', $subInstituteId], ['id', $request->id]])->first();

        $data['data']['view'] = DynamicModel::readRecords($data['data']['table_name']);
        $data['data']['division'] = [];
        $data['data']['standard'] = [];
        $data['data']['term'] =[];
        $data['data']['academic_section'] = [];
        // echo "<pre>";print_r($data);exit;
        return is_mobile($type, "custom_modules.cruds.index", $data, "view");
    }

    public function crudCreate(Request $request, $id, $viewId = 0)
    {
        $data['data'] = CustomModuleTable::with('columns')->find($id);
        $prepareView = [];
        foreach ($data['data']['columns'] as $key => $column) {
            $prepareView[$column['column_name']] = '';
        }
        $prepareView['id'] = 0;
        $data['data']['view'] = $prepareView;
        $data['data']['division'] = [];
        $data['data']['standard'] =[];
        $data['data']['academic_section'] = [];

        if (isset($data['data']['table_name'])) {
            $getRecords = DynamicModel::readSingleRecord($data['data']['table_name'], $viewId);
            if ($getRecords) $data['data']['view'] = $getRecords;
        }
        $type = $request->input('type');
        return is_mobile($type, "custom_modules.cruds.edit", $data, "view");
    }

    public function crudStore(Request $request, $id)
    {
        // echo "<pre>";print_r($request->all());exit;
        $getTable = CustomModuleTable::with('columns')->find($id);
        $fileKeys = collect($getTable['columns'])->where('field_type', 'File')->pluck('column_name')->toArray();
        $checkboxKeys = collect($getTable['columns'])->where('field_type', 'checkbox')->pluck('column_name')->toArray();
        $exceptKey = ['_token', 'view_id', 'submit'];

        $validationKeys = collect($getTable['columns'])->where('not_null',1)->pluck('column_name')->toArray();
        $prepareValidation = [];
        foreach ($validationKeys as $validationKey) {
            $prepareValidation[$validationKey] = 'required';
        }
        if ($id) {
           foreach ($fileKeys as $fileKey) {
               unset($prepareValidation[$fileKey]);
           }
        }

        foreach ($request->files as $key => $file) {
            if (Str::startsWith($key, 'new_')) {
                $exceptKey[] = $key;
            }
        }

        $request->validate($prepareValidation);
        $data = $request->except($exceptKey);
        $columns = collect($data)->keys()->toArray();

        $type = $request->input('type');
        if ($id) {
            if ($request->view_id) {
                foreach ($request->files as $key => $file) {
                    if (Str::startsWith($key, 'new_')) {
                        $imageName = time() . '.' . $file->getClientOriginalExtension();
                        $file->move(public_path('images'), $imageName);
                        $oldKey = Str::replaceFirst('new_', '', $key);
                        $data[$oldKey] = $imageName;

                    }
                }
            }

            foreach ($request->files as $key => $file) {
                if (in_array($key, $fileKeys)) {
                    $imageName = time() . '.' . $file->getClientOriginalExtension();
                    $file->move(public_path('images'), $imageName);
                    $data[$key] = $imageName;
                }
                /*if (Str::startsWith($key, 'image_')) {
                    $imageName = time() . '.' . $file->getClientOriginalExtension();
                    $file->move(public_path('images'), $imageName);
                    $data[$key] = $imageName;
                }*/
            }

            $requestData = $request->all();
            foreach (collect($requestData)->toArray() as $key => $requestValue) {
                if (in_array($key, $checkboxKeys)) {
                    $data[$key] = json_encode($requestValue);
                }
            }


            $data = array_filter($data, function ($key) {
                return strpos($key, 'new_') !== 0;
            }, ARRAY_FILTER_USE_KEY);


            $i=0;
            if (!empty($getTable)) {
                $data['sub_institute_id'] = $request->session()->get('sub_institute_id');
                $dynamicModel = new DynamicModel([], $columns);
                if ($request->view_id) {
                    $i=1;
                    if (Schema::hasColumn($getTable['table_name'], 'updated_by')) {
                        $data['updated_by'] = $request->session()->get('user_id');
                    }
                    if (Schema::hasColumn($getTable['table_name'], 'updated_at')) {
                        $data['updated_at'] = now();
                    }
                    $dynamicModel->updateRecord($getTable['table_name'], $request->view_id, $data);                    
                    // $redirectURL = "/custom-module/create-view/".$id."/update/".$request->view_id;
                } else {
                    $i=1;
                    if (Schema::hasColumn($getTable['table_name'], 'updated_by')) {
                        $data['created_by'] = $request->session()->get('user_id');
                    }
                    if (Schema::hasColumn($getTable['table_name'], 'updated_at')) {
                        $data['created_at'] = now();
                    }
                    $dynamicModel->createRecord($getTable['table_name'], $data);                    
                    // $redirectURL = "/custom-module/create-view/" . $id; 
                }
            }
        }
        // return is_mobile($type, ["route" => "custom_module_crud.index", "id" => $id], null, "redirect", '', 1);
         // $res added by uma on 24-03-2025
         if($i!=0){
            $res['status']= 1;
            $res['message']='Added Successfully';
        }
        else{
            $res['status']= 0;
            $res['message']='Failed to Add Data';
        }
        // $res added by uma on 24-03-2025
        // return is_mobile($type, ["route" => "custom_module_table_column.create", "id" => $id], $res, "redirect", '', 1);
        return is_mobile($type, "/custom-module/".$id, $res, "route_with_id");

    }

    public function viewDelete(Request $request, $id)
    {
        $type = $request->input('type');
        $i=0;
        if ($id > 0 && $request->table_name) {
            $i=1;
            DynamicModel::deleteRecord($request->table_name, $id);
        }
         // $res added by uma on 24-03-2025
         if($i!=0){
            $res['status']= 1;
            $res['message']='Added Successfully';
        }
        else{
            $res['status']= 0;
            $res['message']='Failed to Add Data';
        }
        // return is_mobile($type, ["route" => "custom_module_crud.index", "id" => $request->view_id], null, "redirect", '', 1);
        return is_mobile($type, "/custom-module/".$request->view_id, $res, "route_with_id");
    }

    public function menuLevel2(Request $request){
        return $request;
    }
}

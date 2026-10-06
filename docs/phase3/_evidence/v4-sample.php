<?php
/**
 * V4 - 30-claim sample, re-derived FROM SCRATCH.
 * Write-ups deliberately not re-read first. NUMERIC + DB-checkable NEGATIVE rows.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

function has_col($t, $c) {
    try { return count(DB::select("SHOW COLUMNS FROM `$t` LIKE ?", [$c])) > 0; }
    catch (\Throwable $e) { return null; }
}
function tbl($t) { try { return DB::table($t)->count(); } catch (\Throwable $e) { return -1; } }

echo "--- NUMERIC ---\n";
printf("N1  s_user_skill_jobrole rows      %d\n", tbl('s_user_skill_jobrole'));
printf("N2  s_jobrole_skills rows          %d\n", tbl('s_jobrole_skills'));
printf("N3  s_jobrole_task rows            %d\n", tbl('s_jobrole_task'));
printf("N4  s_users_skills rows            %d\n", tbl('s_users_skills'));
printf("N5  lms_course_enroll rows         %d\n", tbl('lms_course_enroll'));
printf("N6  lms_content_progress rows      %d\n", tbl('lms_content_progress'));
printf("N7  lms_certificates rows          %d\n", tbl('lms_certificates'));
printf("N8  s_user_knowledge.compliance_relevance populated %d\n",
    DB::table('s_user_knowledge')->whereNotNull('compliance_relevance')->where('compliance_relevance','<>','')->count());

echo "\n--- RIGHTS MATRIX ---\n";
$rt = 'tblgroupwise_rights_g2g';
printf("N9  %s rows              %d\n", $rt, tbl($rt));
foreach (['can_view','can_add','can_edit','can_delete'] as $c) {
    if (has_col($rt, $c)) printf("     %-11s =1 on %d rows\n", $c, DB::table($rt)->where($c,1)->count());
}

echo "\n--- NEGATIVE (schema) ---\n";
printf("G1  s_skill_matrix has sub_institute_id?   %s\n", var_export(has_col('s_skill_matrix','sub_institute_id'), true));
printf("G2  s_user_jobrole_task has a skill/competency column? %s\n",
    var_export(has_col('s_user_jobrole_task','skill_id') || has_col('s_user_jobrole_task','competency_id'), true));
printf("G3  certification TYPE table exists?       %s\n", var_export(tbl('certification_type') >= 0, true));
printf("G4  task_status_history exists?            %s\n", var_export(tbl('task_status_history') >= 0, true));
printf("G5  s_jobrole has sub_institute_id?        %s\n", var_export(has_col('s_jobrole','sub_institute_id'), true));
printf("G6  master_skills has sub_institute_id?    %s\n", var_export(has_col('master_skills','sub_institute_id'), true));

<?php


use App\Http\Controllers\front_desk\book_list\book_listController;
use App\Http\Controllers\lms\assignment\annotateAssignmentController;
use App\Http\Controllers\lms\assignment\assignmentController;
use App\Http\Controllers\lms\assignment\assignmentSubmissionController;
use App\Http\Controllers\lms\bulk_chapter_uploadController;
use App\Http\Controllers\lms\chapterController;
use App\Http\Controllers\lms\contentController;
use App\Http\Controllers\lms\counselling\counselling_questionmasterController;
use App\Http\Controllers\lms\counselling\counsellingExamController;
use App\Http\Controllers\lms\counselling\lmsCounsellingController;
use App\Http\Controllers\lms\counselling\MBTIController;
use App\Http\Controllers\lms\courseController;
use App\Http\Controllers\lms\flashcard\flashcardController;
use App\Http\Controllers\lms\leaderboard\lbMasterController;
use App\Http\Controllers\lms\lessonplan\lms_lessonplanController;
use App\Http\Controllers\lms\lms_apiController;
use App\Http\Controllers\lms\lms_contentCategoryController;
use App\Http\Controllers\lms\lmsActivityStreamController;
use App\Http\Controllers\lms\lmsCommunicationController;
use App\Http\Controllers\lms\lmsDoubtController;
use App\Http\Controllers\lms\lmsDoubtConversationController;
use App\Http\Controllers\lms\lmsexamController;
use App\Http\Controllers\lms\lmsLeaderboardController;
use App\Http\Controllers\lms\lmsmappingController;
use App\Http\Controllers\lms\lmsPortfolioController;
use App\Http\Controllers\lms\lmsSocialCollabrotiveController;
use App\Http\Controllers\lms\lmsVirtualClassroomController;
use App\Http\Controllers\lms\locategoryController;
use App\Http\Controllers\lms\loindicatorController;
use App\Http\Controllers\lms\lomasterController;
use App\Http\Controllers\lms\onlineExamController;
use App\Http\Controllers\lms\questionmasterController;
use App\Http\Controllers\lms\questionpaperController;
use App\Http\Controllers\lms\reports\examWiseProgressReportController;
use App\Http\Controllers\lms\reports\studentReportController;
use App\Http\Controllers\lms\subtopicController;
use App\Http\Controllers\lms\teacher_resource\lms_teacherResourceController;
use App\Http\Controllers\lms\topicController;
use App\Http\Controllers\lms\questionWiseReportController;
use App\Http\Controllers\bazar\bulkUploadSheetController;
use App\Http\Controllers\bazar\bulkUploadedReportController;
use App\Http\Controllers\lms\pal\palController;
use App\Http\Controllers\lms\virtualclassroomController;
use App\Http\Controllers\school_setup\sub_std_mapController;
use App\Http\Controllers\lms\lmsDashboardController;
use App\Http\Controllers\lms\lmsCurriculumController;
use App\Http\Controllers\front_desk\syllabus\syllabusController;
use App\Http\Controllers\lms\content_library\contentLibraryController;
use App\Http\Controllers\lms\curriculum\curriculumLessonplanController;
use App\Http\Controllers\lms\library\skillLibraryController;
use App\Http\Controllers\lms\library\H5PController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'lms', 'middleware' => ['auth','session','menu']], function () {
    Route::get('o-net-data-category',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'index'])->name('o-net-data-category.index');
    Route::get('o-net-data-list',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'ONetDataTable'])->name('o-net-data-table.show-list');
    Route::get('o-net-data-list-details',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'ONetDataTableListDetails'])->name('o-net-data-table.show-list-details');
    Route::get('o-net-data-category/show',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'showCategoryWiseData'])->name('o-net-data.show-category');
    Route::get('o-net-data-category/show-occupation-detail',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'showCategoryWiseOccupationData'])->name('o-net-data.show-occupation-detail');
    Route::get('o-net-data-category/show-occupation-detail-list',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'showCategoryWiseOccupationDataList'])->name('o-net-data.show-occupation-detail-list');
    Route::get('o-net-data-category/show-occupation-detail-list-summary',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'showCategoryWiseOccupationDataListSummary'])->name('o-net-data.show-occupation-detail-list-summary');

    Route::get('career_counselling',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'career_counselling'])->name('career_counselling');
    Route::get('career_counselling_education',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'career_counselling_education'])->name('career_counselling_education');
    Route::get('career_counselling_knowing-yourself',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'career_counselling_ky'])->name('career_counselling_knowing-yourself');
    Route::get('career_report',[\App\Http\Controllers\lms\ONetOnlineDataController::class,'career_report'])->name('career_report');
    Route::resource('syllabus', syllabusController::class);
	Route::get('generateSyllabus', [syllabusController::class, 'GenrateAISyllabus'])->name("generateSyllabus");
    Route::resource('chapter_master', chapterController::class);
    Route::resource('course_master', courseController::class);
    Route::resource('topic_master', topicController::class);
    Route::resource('subtopic_master', subtopicController::class);
    Route::get('chapter_search', [chapterController::class, 'chapter_search'])->name('chapter_search');
    Route::resource('content_master', contentController::class);
    Route::get('create_content_master', [contentController::class, 'createChapter'])->name('create_content_master');
    Route::post('store_content_master', [contentController::class, 'storeChapter'])->name('store_content_master');

    Route::resource('virtual_classroom_master', virtualclassroomController::class);

    Route::get('ajax_SubjectwiseChapter', [contentController::class, 'ajax_SubjectwiseChapter'])->name('ajax_SubjectwiseChapter');
    Route::get('ajax_ChapterwiseTopic', [contentController::class, 'ajax_ChapterwiseTopic'])->name('ajax_ChapterwiseTopic');

    Route::get('ajax_ChapterwiseLOmaster', [lomasterController::class, 'ajax_ChapterwiseLOmaster'])->name('ajax_ChapterwiseLOmaster');

    Route::resource('lmsdashboard', lmsDashboardController::class);
    Route::get('lmsdashboard_teacher', [lmsDashboardController::class,'teacherIndex'])->name('teacherIndex');
    
    Route::resource('lo_master', lomasterController::class);

    Route::resource('lo_indicator', loindicatorController::class);

    Route::resource('lo_category', locategoryController::class);

    Route::resource('skill_library',skillLibraryController::class);
    
    Route::get('skill_library/{id}/delete', [skillLibraryController::class, 'destroy']);
    Route::get('skill_library/{id}/show', [skillLibraryController::class, 'show']);

    // multi delete questions
    Route::get('multi_delete_questions', [questionmasterController::class, 'ajax_multiDeleteQuestion'])->name('multi_delete_questions');

    Route::get('ajax_chapterDependencies', [chapterController::class, 'ajax_chapterDependencies'])->name('ajax_chapterDependencies');
    Route::get('ajax_topicDependencies', [topicController::class, 'ajax_topicDependencies'])->name('ajax_topicDependencies');
    Route::get('ajax_questionDependencies', [questionmasterController::class, 'ajax_questionDependencies'])
        ->name('ajax_questionDependencies');
    Route::get('ajax_subStdMappingDependencies', [sub_std_mapController::class, 'ajax_subStdMappingDependencies'])
        ->name('ajax_subStdMappingDependencies');
    Route::get('ajax_questionpaperDependencies', [questionpaperController::class, 'ajax_questionpaperDependencies'])
        ->name('ajax_questionpaperDependencies');

    Route::get('ajax_SubjectwiseLoCategory', [locategoryController::class, 'ajax_SubjectwiseLoCategory'])
        ->name('ajax_SubjectwiseLoCategory');
    Route::get('ajax_LoMasterwiseLoIndicator', [loindicatorController::class, 'ajax_LoMasterwiseLoIndicator'])
        ->name('ajax_LoMasterwiseLoIndicator');

    Route::resource('question_master', questionmasterController::class);
    Route::get('question_mapped_value', [questionmasterController::class, 'getMappedValue'])->name('question_mapped_value');

    Route::post('ajaxdestroyanswer_master', [questionmasterController::class, 'ajaxdestroyanswer_master'])->name('ajaxdestroyanswer_master');
    Route::get('question_chapter_master', [questionmasterController::class, 'indexChapter'])->name('question_chapter_master');

    Route::resource('question_paper', questionpaperController::class);
    Route::post('question_paper/search', [questionpaperController::class,'search']);
    Route::resource('bulk_chapter_upload', bulk_chapter_uploadController::class);
    Route::get('ajax_SubjectwiseQuestion', [questionpaperController::class, 'ajax_SubjectwiseQuestion'])->name('ajax_SubjectwiseQuestion');

    // palController
    Route::resource('pal', palController::class);

    Route::get('ajax_LMS_MappingValue', [contentController::class, 'ajax_LMS_MappingValue'])->name('ajax_LMS_MappingValue');

    Route::get('course_search', [courseController::class, 'course_search'])->name('course_search');

    Route::get('ajax_LMS_StandardwiseSubject', [questionpaperController::class, 'ajax_LMS_StandardwiseSubject'])->name('ajax_LMS_StandardwiseSubject');

    Route::resource('lmsmapping', lmsmappingController::class);
    Route::resource('lmsexam', lmsexamController::class);
    Route::resource('lmsActivityStream', lmsActivityStreamController::class);
    Route::resource('lmsCommunication', lmsCommunicationController::class);
    Route::resource('lmsSocialCollabrotive', lmsSocialCollabrotiveController::class);
    Route::resource('lmsVirtualClassroom', lmsVirtualClassroomController::class);
    Route::resource('lmsPortfolio', lmsPortfolioController::class);
    Route::resource('lmsLeaderboard', lmsLeaderboardController::class);

    Route::get('ajax_AddLMS_MappingFromContent', [lmsmappingController::class, 'ajax_AddLMS_MappingFromContent'])
        ->name('ajax_AddLMS_MappingFromContent');
    Route::resource('online_exam', onlineExamController::class);
    Route::get('online_exam_attempt', [onlineExamController::class, 'online_exam_attempt'])->name('online_exam_attempt');

    Route::get('ajax_LMS_SubjectwiseChapter', [lmsPortfolioController::class, 'ajax_LMS_SubjectwiseChapter'])
        ->name('ajax_LMS_SubjectwiseChapter');

    Route::get('ajax_LMS_SubjectwiseChapterForBooklist', [book_listController::class, 'ajax_LMS_SubjectwiseChapterForBooklist'])
        ->name('ajax_LMS_SubjectwiseChapterForBooklist');

    Route::get('ajax_LMS_ChapterwiseTopic', [lmsPortfolioController::class, 'ajax_LMS_ChapterwiseTopic'])->name('ajax_LMS_ChapterwiseTopic');

    Route::post('ajax_lmsPortfolio_feedback', [lmsPortfolioController::class, 'ajax_lmsPortfolio_feedback'])->name('ajax_lmsPortfolio_feedback');

    Route::resource('lmsDoubt', lmsDoubtController::class);
    Route::resource('lmsCounselling', lmsCounsellingController::class);
    Route::resource('lmsCounsellingQuestion', counselling_questionmasterController::class);
    Route::post('ajaxdestroycounsellinganswer_master', [counselling_questionmasterController::class, 'ajaxdestroycounsellinganswer_master'])
        ->name('ajaxdestroycounsellinganswer_master');
    Route::resource('lmsCounsellingExam', counsellingExamController::class);

    Route::get('lmsIndustryListing', [lmsCounsellingController::class, 'lmsIndustryListing'])->name('lmsIndustryListing');
    Route::get('lmsIndustryListing/careersInIndustry/{id}', [lmsCounsellingController::class, 'careersInIndustry'])->name('lmsIndustryListing.careersInIndustry');
    Route::get('lmsIndustryListing/careersInIndustry/careerReport/{id}', [lmsCounsellingController::class, 'careerReport'])->name('lmsIndustryListing.careersInIndustry.careerReport');
    Route::get('lmsIndustryListing/careersInIndustry/careerReport/resources/{id}/{title}', [lmsCounsellingController::class, 'resources'])->name('lmsIndustryListing.careersInIndustry.careerReport.resources');

    Route::resource('lmsMBTIPaper', MBTIController::class);

    Route::get('ajax_getQuestionList', [onlineExamController::class, 'ajax_getQuestionList'])->name('ajax_getQuestionList');

    Route::resource('lmsDoubtConversation', lmsDoubtConversationController::class);

    Route::resource('lb_master', lbMasterController::class);

    Route::resource('lmsAssignment', assignmentController::class);

    Route::resource('lmsAssignment_submission', assignmentSubmissionController::class);

    Route::resource('lmsAnnotate_assignment', annotateAssignmentController::class);

    Route::resource('lmsStudent_report', studentReportController::class);

    Route::resource('lmsExamwise_progress_report', examWiseProgressReportController::class);
    Route::get('ajax_LMS_SubjectWiseExam', [examWiseProgressReportController::class, 'ajax_LMS_SubjectWiseExam'])
        ->name('ajax_LMS_SubjectWiseExam');

    Route::resource('lms_lessonplan', lms_lessonplanController::class);
    Route::get('ajax_Timetable', [lms_lessonplanController::class, 'ajax_Timetable'])->name('ajax_Timetable');
     Route::GET('ajax_daywisedata', 'lms\lessonplan\lms_lessonplanController@ajax_DayWiseData')->name('ajax_daywisedata');
    Route::GET('ajax_contentmasterdata', 'lms\lessonplan\lms_lessonplanController@ajax_contentMasterData')->name('ajax_contentmasterdata');
    Route::GET('ajax_questionpaperdata', 'lms\lessonplan\lms_lessonplanController@ajax_questionPaperData')->name('ajax_questionpaperdata');
    Route::GET('ajax_daywisedata', 'lms\lessonplan\lms_lessonplanController@ajax_DayWiseData')->name('ajax_daywisedata');

    Route::get('ajax_getTeacher', [lms_lessonplanController::class, 'ajax_getTeacher'])->name('ajax_getTeacher');
    Route::get('get_chat_data', [lms_lessonplanController::class, 'getChatOutput'])->name('get_chat_data');    
    
Route::get('questionReport', [questionWiseReportController::class, 'index'])->name('question_wise_report');
Route::post('show_question_wise_report',
    [questionWiseReportController::class, 'show_question_wise_report'])->name('show_question_wise_report');
    Route::resource('lms_content_category', lms_contentCategoryController::class);

    Route::get('ajax_getYouTubeSuggestion', [contentController::class, 'ajax_getYouTubeSuggestion'])->name('ajax_getYouTubeSuggestion');

    Route::resource('lms_teacherResource', lms_teacherResourceController::class);

    Route::resource('lms_flashcard', flashcardController::class);
    // Route::get('ajax_SaveAnnotations', 'lms\assignment\annotateAssignmentController@ajax_SaveAnnotations')->name('ajax_SaveAnnotations');

    Route::resource('subjectwise_graph', chapterController::class);
    Route::resource('lms_curriculum', lmsCurriculumController::class);
    Route::resource('lms_syllabus', lmsSyllabusController::class);
    Route::resource('content_library', contentLibraryController::class);
    Route::resource('curriculum_lessonplan', curriculumLessonplanController::class);
    Route::get('getMapVals', [contentLibraryController::class,'getMapVals'])->name('getMapVals');
    Route::get('searchContent', [contentLibraryController::class,'getSearchedContent'])->name('searchContent');
    //Route::get('questionReport', 'student\questionWiseReportController@index')->name('question_wise_report');
    //Route::post('show_question_wise_report', 'student\questionWiseReportController@show_question_wise_report')->name('show_question_wise_report');
    // H5p Library
    Route::resource('h5p',H5PController::class);
});

Route::controller(lms_apiController::class)->group(function () {
    Route::post('/studentVirtualClassroomAPI', 'studentVirtualClassroomAPI');
    Route::post('/studentPortfolioAPI', 'studentPortfolioAPI');
    Route::post('/studentSocialCollabrativeAPI', 'studentSocialCollabrativeAPI');
    Route::post('/studentSubjectAPI', 'studentSubjectAPI');
    Route::post('/studentContentAPI', 'studentContentAPI');
    Route::post('/studentQuestionPaperListAPI', 'studentQuestionPaperListAPI');
    Route::post('/studentQuestionPaperAPI', 'studentQuestionPaperAPI');
    Route::post('/studentAssessmentAPI', 'studentAssessmentAPI');
    Route::post('/studentTransportAPI', 'studentTransportAPI');
    Route::post('/studentLeaderBoardAPI', 'studentLeaderBoardAPI');
    Route::post('/studentActivityStreamAPI', 'studentActivityStreamAPI');
    Route::post('/studentBookListAPI', 'studentBookListAPI');
    Route::post('/studentSyllabusAPI', 'studentSyllabusAPI');
    Route::post('/studentQuestionPaperSaveAPI', 'studentQuestionPaperSaveAPI');
    Route::post('/studentAssessmentDetailAPI', 'studentAssessmentDetailAPI');
    Route::post('/lmsCategorywiseSubjectAPI', 'lmsCategorywiseSubjectAPI');
    Route::post('/trizStandardAPI', 'trizStandardAPI');
});

Route::group(['prefix' => 'bazar', 'middleware' => ['auth','session','menu']], function () {
    Route::resource('bulk_upload_sheet', bulkUploadSheetController::class);
    Route::get('bulk_position_data', [bulkUploadSheetController::class, 'bulk_position_data'])->name('bulk_position_data');
    Route::post('store_position_data', [bulkUploadSheetController::class, 'store_position_data'])->name('store_position_data');
    Route::get('bulk_margin_data', [bulkUploadSheetController::class, 'bulk_margin_data'])->name('bulk_margin_data');
    Route::post('store_margin_data', [bulkUploadSheetController::class, 'store_margin_data'])->name('store_margin_data');
    Route::get('bulk_pnl_data', [bulkUploadSheetController::class, 'bulk_pnl_data'])->name('bulk_pnl_data');
    Route::post('store_pnl_data', [bulkUploadSheetController::class, 'store_pnl_data'])->name('store_pnl_data');

    Route::resource('bazar_report', bulkUploadedReportController::class);
    Route::post('show_bazar_report', [bulkUploadedReportController::class, 'show_bazar_report'])->name('show_bazar_report');
});

Route::get('/upcoming', [lmsActivityStreamController::class, 'upcomingActivity'])->name('upcoming');
Route::get('/today', [lmsActivityStreamController::class, 'todayActivity'])->name('today');
Route::get('/recent', [lmsActivityStreamController::class, 'recentActivity'])->name('recent');
Route::get('careerExplore', [lmsCounsellingController::class, 'careerExplore']);
Route::get('careerExploreResult', [lmsCounsellingController::class, 'careerExploreResult']);
Route::get('careerCluster', [lmsCounsellingController::class, 'careerCluster']);
Route::get('allOccupation', [lmsCounsellingController::class, 'allOccupation']);
Route::get('OccupationDetails', [lmsCounsellingController::class, 'OccupationDetails']);
Route::get('getInstituteData', [lmsCounsellingController::class, 'getInstituteData']);
Route::get('getCourseData', [lmsCounsellingController::class, 'getCourseData']);
Route::get('getEmployerData', [lmsCounsellingController::class, 'getEmployerData']);
Route::get('ExploreSector', [lmsCounsellingController::class, 'ExploreSector']);
Route::get('ExpertAdvice', [lmsCounsellingController::class, 'ExpertAdvice']);
Route::get('intrestQuestions', [lmsCounsellingController::class, 'intrestQuestions']);
Route::get('intrestResults', [lmsCounsellingController::class, 'intrestResults']);
Route::get('intrestJobzone', [lmsCounsellingController::class, 'intrestJobzone']);
Route::get('intrestCareers', [lmsCounsellingController::class, 'intrestCareers']);
Route::get('/api/get-curriculum-list', [lmsSyllabusController::class, 'getCurriculums']);
Route::get('intrestEnterScore', [lmsCounsellingController::class, 'intrestEnterScore'])->name('intrestEnterScores');
Route::get('intrestArea', [lmsCounsellingController::class, 'intrestArea']);
Route::get('matchProfile', [lmsCounsellingController::class, 'matchProfile']);
Route::post('/ai/processData',[contentController::class,'processAIData'])->name('ai.processData');
Route::post('/ai/generateLessonPlan', [contentController::class, 'generateLessonPlan'])->name('ai.generateLessonPlan');
Route::post('/ai/generateLessonPlanNew', [contentController::class, 'generateLessonPlanNew'])->name('ai.generateLessonPlanNew');
Route::post('/ai/generateSportsData', [contentController::class, 'generateSportsData'])->name('ai.generateSportsData');
Route::post('/paraphraseNew', [ParaphraseController::class, 'paraphrase']);
Route::post('/set-book-session',[contentController::class,'setBookSession'])->name('set-book-session');

Route::get('/download-File', [contentLibraryController::class, 'downloadFile'])->name('downloadFile');

// use App\Http\Controllers\lms\Neo4jSyncController;
// use App\Http\Controllers\lms\GraphController;
// use App\Http\Controllers\lms\RecommendationController;
// use App\Http\Controllers\lms\GraphControllerNew;


// Route::get('/get-students', [GraphControllerNew::class, 'getStudents']);
// Route::get('/get-related-data/{nodeId}', [GraphControllerNew::class, 'getRelatedData']);
// Route::get('/get-chapters-for-subject/{subjectId}', [GraphControllerNew::class, 'getChaptersForSubject']);
// Route::get('/get-questions-for-chapter/{chapterId}', [GraphControllerNew::class, 'getQuestionsForChapter']);
// Route::get('/recommendations', [RecommendationController::class, 'getRecommendations']);
// Route::get('/graph-data', [GraphController::class, 'getGraphData']);
// Route::get('/graph-data-learning-path', [GraphController::class, 'getLearningPath']);

// Route::get('/welcome', function () {
//     return view('welcomenew');
// });
// Route::get('/dashboard', function () {
//     return view('recommend');
// });
// Route::get('/sync-neo4j', [Neo4jSyncController::class, 'sync']);

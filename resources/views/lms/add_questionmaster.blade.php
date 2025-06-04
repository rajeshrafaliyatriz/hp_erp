{{--@include('includes.lmsheadcss')--}}
@extends('lmslayout')
@section('container')
<link href="/plugins/bower_components/clockpicker/dist/jquery-clockpicker.min.css" rel="stylesheet">
<!-- <link href="{{ asset('/plugins/bower_components/summernote/dist/summernote.css') }}" rel="stylesheet" /> -->
<style>
.tooltip-inner {
    max-width: 1100px !important;
}
br {
     display: block !important;
}
</style>
{{--@include('includes.header')
@include('includes.sideNavigation')--}}
<!-- Content main Section -->
<div id="page-wrapper">
    <div class="container-fluid mb-5">
        <div class="row bg-title align-items-center justify-content-between">
            <div class="col-lg-6 col-md-4 col-sm-4 col-xs-12 mb-4">
                <h1 class="h4 mb-3">Add Question Answer</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent p-0 mb-0">
                        <li class="breadcrumb-item"><a href="{{route('course_master.index')}}">LMS</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('chapter_master.index',['standard_id'=>$data['breadcrum_data']->standard_id,'subject_id'=>$data['breadcrum_data']->subject_id]) }}">{{$data['breadcrum_data']->subject_name}}</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('topic_master.index',['id'=>$data['breadcrum_data']->chapter_id]) }}">{{$data['breadcrum_data']->chapter_name}}</a></li>
                        @isset($data['topic_id'])
                            <li class="breadcrumb-item"><a href="{{ route('topic_master.index',['id'=>$data['breadcrum_data']->chapter_id]) }}">{{$data['breadcrum_data']->topic_name}}</a></li>
                        @endisset
                        <li class="breadcrumb-item active" aria-current="page">Add Question Answer</li>
                    </ol>
                </nav>
            </div>
        </div>
        <div class="card border-0">
            <div class="card-body">
                <form action="{{ route('question_master.store') }}" method="post" enctype='multipart/form-data'>
                    {{ method_field("POST") }}
                    @csrf

                    <input type="hidden" name="grade_id" id="grade_id" value="{{$data['grade_id']}}">
                    <input type="hidden" name="standard_id" id="standard_id" value="{{$data['standard_id']}}">
                    <input type="hidden" name="subject_id" id="subject_id" value="{{$data['subject_id']}}">
                    <input type="hidden" name="chapter_id" id="chapter_id" value="{{$data['chapter_id']}}">
                    @isset($data['topic_id'])
                        <input type="hidden" name="topic_id" id="topic_id" value="{{$data['topic_id']}}">
                    @endisset


                    <div class="row">
                        <div class="col-md-8">

                            <div class="row align-items-center">
                                <div class="col-md-12">
                                <div class="d-flex">                                
                                    <input type="text" class="form-control" id="question_prompt" name="question_prompt" value="get 1 question for standard '{{$data['breadcrum_data']->standard_name}}' and subject '{{$data['breadcrum_data']->subject_name}}' and chapter '{{$data['breadcrum_data']->chapter_name}}' @isset($data['topic_id']) and for topic '{{$data['breadcrum_data']->topic_name}}' @endisset "><span onclick="question_prompt();" style="padding:2px 6px"><i class="mdi mdi-refresh"></i></span>
                                </div>

                                    <div class="form-group">
                                        <textarea name="question_title" id="question_title" contenteditable="true" onchange="check_input(this)">

                                        </textarea>
                                        <label for="topicType">Question</label>
                                        <!-- <input type="text" class="form-control" id="question_title" name="question_title" placeholder="Enter Question"> -->
                                        <!-- <textarea class="summernote" id="question_title" name="question_title"></textarea> -->
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="description">Description</label>
                                        <textarea type="text" rows="4" class="form-control mb-0" name="description" id="description" placeholder="Description"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="addButtonCheckbox1">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="topicType">Mapping Type</label>
                                    <select class="load_map_value cust-select form-control mb-0" name="mapping_type[]" data-new = "1" id="mapping_type">
                                        <option value="">Select Mapping Type</option>
                                        @if(isset($data['lms_mapping_type']))
                                            @foreach($data['lms_mapping_type'] as $key => $value)
                                            <option value="{{$value['id']}}">{{$value['name']}}</option>
                                            @endforeach
                                        @endif
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="topicType2">Mapping Value</label>
                                    <select class="cust-select form-control map-value mb-0" name="mapping_value[]" data-new="1" id="mapping_value">
                                        <option value="">Select Mapping Value</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="topicType2">Reason</label>
                                  <textarea name="reasons[]" class="form-control" data-reason="1" row="2"></textarea>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <a href="javascript:void(0);" onclick="addNewRow1();" class="btn btn-success btn-sm mr-2"><i class="mdi mdi-plus"></i></a>
                            </div>
                        </div>


                    </div>
                    <div class="col-md-6">
                                <div class="form-group">
                                    <label for="topicType2">Learning Outcome</label>
                                  <textarea name="learning_outcome" class="form-control" row="2"></textarea>
                                </div>
                            </div>
                    <div class="col-md-8 border">
                        <label for="title" class="mt-2 text-primary font-weight-bold">Pre Topic</label>
                        {{ App\Helpers\LMSSearchChain('3','single','pre',$data['standard_id'],'std,sub,chapter,topic',"","","") }}
                    </div>

                    <div class="mt-2 mb-4 col-md-8 border">
                        <label for="title" class="mt-2 text-primary font-weight-bold">Post Topic</label>
                        {{ App\Helpers\LMSSearchChain('3','single','post',$data['standard_id'],'std,sub,chapter,topic',"","","") }}
                    </div>

                    <div class="mt-2 mb-4 col-md-8 border">
                        <label for="title" class="mt-2 text-primary font-weight-bold">Cross Curriculum</label>
                        {{ App\Helpers\LMSSearchChain('3','single','cross-curriculum',$data['standard_id'],'std,sub,chapter,topic',"","","") }}
                    </div>

                    <div class="row">
                       <!--  <div class="col-md-4">
                            <div class="form-group">
                                <label for="topicType">Pre Grade Topic</label>
                                <input type="text" class="form-control" id="pre_grade_topic" name="pre_grade_topic" placeholder="Enter Pre Grade Topic">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="topicType">Post Grade Topic</label>
                                <input type="text" class="form-control" id="post_grade_topic" name="post_grade_topic" placeholder="Enter Post Grade Topic">
                            </div>
                        </div> -->
                        <div class="col-md-8">
                        <div class="form-group">
                                <label for="question_type_id">Question Type:</label>
                                <select name="question_type_id" id="question_type_id" class="form-control" required onchange="show_ans(this.value);"
                                @if(isset($data['questionmaster_data']['question_type_id'])) disabled  @endif>
                                    <option value="">Select Question Type</option>
                                        @foreach($data['questiontype_data'] as $key1 => $value1)
                                        <option value="{{$value1['id']}}" @if(isset($data['questionmaster_data']['question_type_id'])) @if($data['questionmaster_data']['question_type_id']==$value1['id']) selected='selected' @endif @endif>{{ucwords($value1['question_type'])}}</option>
                                        @endforeach
                                </select>
                                @if(isset($data['questionmaster_data'])) <input type="hidden" name="hid_question_type_id" value="{{$data['questionmaster_data']['question_type_id']}}"> @endif
                            </div>
                            <div class="form-group">
                                <label for="points">Question Mark</label>
                                <input type="text" class="form-control" id="points" name="points" placeholder="Question Mark" required>
                            </div>
                            <div class="form-group">
                                <label for="concept">Concept</label>
                                <input type="text" class="form-control" id="concept" name="concept" placeholder="Concept">
                                <label for="subconcept">Sub Concept</label>
                                <input type="text" class="form-control" id="subconcept" name="subconcept" placeholder="Sub Concept">
                            </div>
                            <div class="form-group">
                                <label for="answer">Answer</label>
                                <textarea class="form-control" id="answer" name="answer"></textarea>
                                <label for="hint_text">Hint</label>
                                <textarea class="form-control" id="hint_text" name="hint_text"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="multiple_answer">Multiple Answers</label>
                                <input type="checkbox" id="multiple_answer" name="multiple_answer" value="1" onclick="add_checkbox(this);"
                                @if( isset($data['questionmaster_data']['multiple_answer']) && $data['questionmaster_data']['multiple_answer'] == 1)
                                checked
                                @endif
                                @if(isset($data['questionmaster_data'])) disabled  @endif
                                >
                            </div>
                            <div class="form-group">
                                <label for="status">Show</label>
                                <input type="checkbox" id="status" name="status" value="1"
                                @if( isset($data['questionmaster_data']['status']) && $data['questionmaster_data']['status'] == 1)
                                checked
                                @elseif(!isset($data['questionmaster_data']))
                                checked
                                @endif
                                >
                            </div>
                            <div class="border rounded mb-3 mb-md-4 mt-3 p-4 main_option_div" style="display:none1;">
                                <div class="h4 mb-3">Answer</div>
                                <div class="form-group row addButtonCheckbox mb-3 align-items-center">
                                    <label for="colFormLabel" class="col-sm-1 col-form-label my-2">Options</label>
                                    <div class="col-sm-4 my-2">
                                        <input type="text" class="form-control option_class mb-0" id='options[NEW][]' name='options[NEW][]' placeholder="Enter Option" required>
                                    </div>
                                    <div class="col-sm-4 my-2">
                                        <input type="text" class="form-control mb-0" id='feedback[NEW][]' name='feedback[NEW][]' placeholder="Enter Feedback">
                                    </div>
                                    <div class="col-sm-1">
                                        <div class="custom-control custom-radio my-2">
                                            <input type="radio" class="form-control radio_checkbox mb-0" name="correct_answer[]" value="0">
                                        </div>
                                    </div>
                                    <div class="col-md-2 my-2">
                                        <a href="javascript:void(0);" onclick="addNewRow();" class="btn btn-success btn-sm mr-2 mb-0"><i class="mdi mdi-plus"></i></a>
                                    </div>
                                </div>
                                <div class="clearfix"></div>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit">Submit</button>
                </form>
            </div>
        </div>
    </div>
</div>
@include('includes.lmsfooterJs')
<script>
    $(document).on("focusout","#cke_1_contents .cke_wysiwyg_div, #cke_1_contents .cke_enable_context_menu",function(){

        if ( $(this).hasClass('cke_wysiwyg_div') ) {
            var question = $(this).html();
        } else {
            var question = $(this).val();
        }

        // if ( question.length > 2 ) {
        //     $.ajax({
        //         url: "/api/get-bloom-texonomy?question="+ question,
        //         method: 'GET',
        //         dataType: "json",
        //         success: function(res) {
        //             console.log(res.prediction);
        //             if (res.prediction) {
        //                 $('.load_map_value.cust-select').val(82);
        //                 var path = "{{ route('ajax_LMS_MappingValue') }}";
        //                 $.ajax({
        //                     url:path,
        //                     data:'mapping_type=82',
        //                     success:function(result){
        //                         var e = $('select[name="mapping_value[]"][data-new=1]');
        //                         $(e).find('option').remove().end();
        //                         for(var i=0;i < result.length ;i++)
        //                         {
        //                             console.log(res.prediction, result[i]['name']);
        //                             $(e).append($("<option></option>").val(result[i]['id']).html(result[i]['name']));
        //                         }

        //                         var prediction = res.prediction;

        //                         $('.map-value option').filter(function () { return $(this).html() == `${prediction}`; }).attr('selected', true);

        //                     }
        //                 });
        //             }
        //         }
        //     });
        //
    });

</script>
<!-- <script src="{{asset('/plugins/bower_components/summernote/dist/summernote.min.js')}}"></script> -->
<script src="{{ asset("/ckeditor_wiris/ckeditor4/ckeditor.js") }}"></script>
<script>

    CKEDITOR.config.toolbar_Full =
        [
        { name: 'document', items : [ 'Source'] },
        { name: 'clipboard', items : [ 'Cut','Copy','Paste','-','Undo','Redo' ] },
        { name: 'editing', items : [ 'Find'] },
        { name: 'basicstyles', items : [ 'Bold','Italic','Underline'] },
        { name: 'paragraph', items : [ 'JustifyLeft','JustifyCenter','JustifyRight'] }
        ];
    CKEDITOR.config.height = '40px';

    CKEDITOR.plugins.addExternal('divarea', '../examples/extraplugins/divarea/', 'plugin.js');
    CKEDITOR.plugins.addExternal('sharedspace', '../examples/extraplugins/sharedspace/', 'plugin.js');
    CKEDITOR.plugins.addExternal('filebrowser', '../examples/extraplugins/filebrowser/', 'plugin.js');
    CKEDITOR.plugins.addExternal('enterkey', '../examples/extraplugins/enterkey/', 'plugin.js');
    CKEDITOR.plugins.addExternal('FMathEditor', '../examples/extraplugins/FMathEditor/', 'plugin.js');
    CKEDITOR.config.removePlugins = 'maximize';
    CKEDITOR.config.removePlugins = 'resize';
    CKEDITOR.config.sharedSpaces = { top: 'toolbar1'};
    CKEDITOR.replace('question_title', {
         extraPlugins: 'filebrowser,divarea,sharedspace,FMathEditor,enterkey',
         enterMode: '2',
         language: 'en',
         filebrowserUploadUrl: "{{route('uploadimage',['_token' => csrf_token() ])}}",
         filebrowserUploadMethod: 'form'
    });
    var editor = CKEDITOR.instances['question_title'];

editor.on('blur', function() {
       // Call the check_input function when the CKEditor loses focus
       check_input(editor.getData());
   });
</script>


<script>

$(document).on('change','.load_map_value', function(e){
    e.stopPropagation();
    e.preventDefault();
    var mapping_type = $(this).val();
    var data_new = $(this).attr('data-new');
    //alert(mapping_type);

    var path = "{{ route('ajax_LMS_MappingValue') }}";
    $.ajax({
        url:path,
        data:'mapping_type='+mapping_type,
        success:function(result){
            var e = $('select[name="mapping_value[]"][data-new='+data_new+']');
            $(e).find('option').remove().end();
            for(var i=0;i < result.length ;i++)
            {
                $(e).append($("<option></option>").val(result[i]['id']).html(result[i]['name']));
            }
        }
    });

});



//START Multiple LMS mapping
function addNewRow1(){
    $('select[name="mapping_type[]"]').each(function(){
        data_new =  parseInt($(this).attr('data-new'));
        html = $(this).html();
    });
    data_new = parseInt(data_new) + 1;

    var mapping_type_data = html;//$('#mapping_type:first').html();
    var htmlcontent = '';
    htmlcontent += '<div class="clearfix"></div><div class="addButtonCheckbox1"><div class="row align-items-center">';

    htmlcontent += '<div class="col-md-3"><div class="form-group"><label for="topicType">Mapping Type</label><select class="load_map_value cust-select form-control mb-0" name="mapping_type[]" data-new='+data_new+'>'+mapping_type_data+'</select></div></div>';
    htmlcontent += '<div class="col-md-3"><div class="form-group"><label for="topicType2">Mapping Value</label><select class="cust-select form-control mb-0" name="mapping_value[]" data-new='+data_new+'><option value="">Select Mapping Value</option></select></div></div>';
    htmlcontent += '<div class="col-md-3"><div class="form-group"><label for="topicType2">Reasons</label><input name="reasons[]" data-reason='+data_new+' type="text" class="form-control"></div></div>';
    htmlcontent += '<div class="col-md-3"><a href="javascript:void(0);" onclick="removeNewRow1();" class="btn btn-danger btn-sm"><i class="mdi mdi-minus"></i></a></div></div></div>';

    $('.addButtonCheckbox1:last').after(htmlcontent);
}
function removeNewRow1() {
    $(".addButtonCheckbox1:last" ).remove();
}
//END Multiple LMS mapping

function addNewRow(){
    var elements = document.getElementsByClassName("radio_checkbox");
    var count = 0;
    for(var i = 0; i < elements.length;i++){
            count++;
    }

    if($("#multiple_answer").prop("checked") == true){
        types = "checkbox";
    }else{
        types = "radio";
    }
    var html = '';

    html += '<div class="clearfix"></div><div class="form-group row addButtonCheckbox mb-3 align-items-center">';
    html += '<label for="colFormLabel" class="col-sm-1 col-form-label my-2">Options</label><div class="col-sm-4 my-2"><input type="text" class="form-control option_class mb-0" id="options[NEW][]" name="options[NEW][]" placeholder="Enter Option"></div>';
    html += '<div class="col-sm-4 my-2"><input type="text" class="form-control mb-0" id="feedback[NEW][]" name="feedback[NEW][]" placeholder="Enter Feedback"></div>';
    html += '<div class="col-sm-1 my-2"><div class="custom-control custom-radio mb-0"><input type="'+types+'" class="form-control radio_checkbox" name="correct_answer[]" value="'+count+'"></div></div>';
    html += '<div class="col-md-2 my-2"><a href="javascript:void(0);" onclick="removeNewRow();" class="btn btn-danger btn-sm mr-2 mb-0"><i class="mdi mdi-minus"></i></a></div></div>';
    $('.addButtonCheckbox:last').after(html);
}

function removeNewRow() {
    $(".addButtonCheckbox:last" ).remove();
}

function show_ans(question_type)
{
    if(question_type == 1)//Show Multiple Answer block
    {
        $(".main_option_div").show();
        $(".option_class").attr('required', 'true');
    }else{ //Show Narrrative Answer block
        $(".main_option_div").hide();
        $(".option_class").removeAttr('required');
    }
}

function add_checkbox()
{
    if($("#multiple_answer").prop("checked") == true){
        $(".radio_checkbox").attr('type', 'checkbox');
    }else{
        $(".radio_checkbox").attr('type', 'radio');
    }
}

function removeNewRowAjax(id) {
    var elements = document.getElementsByClassName("radio_checkbox");
    var count = 0;
    for(var i = 0; i < elements.length;i++){
            count++;
    }
    count = (count-1);
    if(count == 1)
    {
        alert("Atleast one answer is required");
    }
    else{
        var path = "{{ route('ajaxdestroyanswer_master') }}";
        $.ajax({
            url: path,
            type:'post',
            data: {"id": id},
            success: function(result){
                $("#optionblock_"+id).remove();
            }
        });
    }
}

function getStandardwiseDivision(std_id){
    var path = "{{ route('ajax_StandardwiseDivision') }}";
    $('#division_id').find('option').remove().end().append('<option value="">Select Division</option>').val('');
    $.ajax({url: path,data:'standard_id='+std_id, success: function(result){
        for(var i=0;i < result.length;i++){
            $("#division_id").append($("<option></option>").val(result[i]['division_id']).html(result[i]['name']));
        }
    }
    });
}

$( document ).ready(function() {
   question_prompt();
    $("#standard").change(function(){
        var std_id = $("#standard").val();
        var path = "{{ route('ajax_StandardwiseSubject') }}";
        $('#subject').find('option').remove().end().append('<option value="">Select Subject</option>').val('');
        $.ajax({url: path,data:'std_id='+std_id, success: function(result){
            for(var i=0;i < result.length;i++){
                $("#subject").append($("<option></option>").val(result[i]['subject_id']).html(result[i]['display_name']));
            }
        }
        });
    })
});
function question_prompt(){
    $('#question_title').empty();
    var editor = CKEDITOR.instances['question_title'];
    if (editor) {
        editor.setData('');
    }
    var subject = $("#subject_id").val();
    var standard = $("#standard_id").val();
    var chapter = "{{$_REQUEST['chapter_id']}}";
    var topic = "{{$data['breadcrum_data']->topic_id ?? ''}}";
    var question_prompt = $('#question_prompt').val();
    
    var search="question";
    var path = "{{ route('chat') }}";
    $.ajax({
        url:path,
        data: {standard:standard,subject_id:subject,chapter_id:chapter,topic_id:topic,question_prompt:question_prompt,search:search},
        success:function(result){
            if (editor) {
                editor.setData('');
            }
            console.log('question-'+result);
            if(Array.isArray(result)){
              var data =result[0].substring(1, result[0].length - 1);
            }else{
              var data = result;
            }
            // Append the new content
            $('#question_title').append(data);
            check_input(data);
            // Optionally, you can set the new content directly to CKEditor
            if (editor) {
                editor.setData(data);
            }
        }

    })
}
//START Bind chapters
$("#subject").change(function(){
    var subject = $("#subject").val();
    var standard = $("#standard").val();
    var path = "{{ route('ajax_SubjectwiseChapter') }}";
    $('#chapter').find('option').remove().end().append('<option value="">Select Chapter</option>').val('');
    $.ajax({
        url:path,
        data:'sub_id='+subject+'&std_id='+standard,
        success:function(result){
            for(var i=0;i < result.length ;i++)
            {
                $("#chapter").append($("<option></option>").val(result[i]['id']).html(result[i]['chapter_name']));
            }
        }
    });

    //START Bind LO Category
    var path = "{{ route('ajax_SubjectwiseLoCategory') }}";
    $('#locategory').find('option').remove().end().append('<option value="">Select Lo Category</option>').val('');
    $.ajax({
        url:path,
        data:'sub_id='+subject+'&std_id='+standard,
        success:function(result){
            for(var i=0;i < result.length ;i++)
            {
                $("#locategory").append($("<option></option>").val(result[i]['id']).html(result[i]['title']));
            }
        }
    });
    //END Bind LO Category
})
//END Bind chapters

//START Bind LO Master
$("#chapter").change(function(){
    var chapter = $("#chapter").val();

    var path = "{{ route('ajax_ChapterwiseLOmaster') }}";
    $('#lomaster').find('option').remove().end();
    $.ajax({
        url:path,
        data:'chapter_id='+chapter,
        success:function(result){
            for(var i=0;i < result.length ;i++)
            {
                $("#lomaster").append($("<option></option>").val(result[i]['id']).html(result[i]['title']));
            }
        }
    });
})
//END Bind LO Master

//START Bind LO Indicator
$("#lomaster").change(function(){
    var lomaster = $("#lomaster").val();
    var path = "{{ route('ajax_LoMasterwiseLoIndicator') }}";
    $('#loindicator').find('option').remove().end();
    $.ajax({
        url:path,
        data:'lomaster_ids='+lomaster,
        success:function(result){
            for(var i=0;i < result.length ;i++)
            {
                $("#loindicator").append($("<option></option>").val(result[i]['id']).html(result[i]['indicator']));
            }
        }
    });
})
//END Bind LO Indicator
</script>

 <script>
//map value

// Define the load_map_value function
function load_map_value(data_new, selectedValue,map_val) {
    var mapping_type = $('select[name="mapping_type[]"][data-new=' + data_new + ']').val();
    var path = "{{ route('ajax_LMS_MappingValue') }}";
    $.ajax({
        url: path,
        data: 'mapping_type=' + mapping_type,
        success: function(result) {
            var e = $('select[name="mapping_value[]"][data-new=' + data_new + ']');
            $(e).find('option').remove().end();
            for (var i = 0; i < result.length; i++) {
                $(e).append($("<option></option>").val(result[i]['id']).html(result[i]['name']));
                if (result[i]['name'] === map_val) {
                    $(e).find('option[value="' + result[i]['id'] + '"]').attr('selected', true);
                }
            }
        }
    });
}

// map type
function check_input(inputElement) {

    var inputValue = inputElement.value;
    var editor = CKEDITOR.instances['question_title'];
   
// Get the content from the CKEditor instance
   var inputValue = $('#question_title').val();
    var std = "{{$data['breadcrum_data']->standard_name}}";

      var data = {
        "question": inputValue,
        "standard": std,
        "type_depth":9,
        "type_bloom":82,
        "type_learning":"learn",
    };

    var path = "{{ route('chat') }}";
    $.ajax({
        url:path,
        data: data,
        success:function(result){
            console.log(result);
            var selectElement_type = document.getElementById("mapping_type");

            $('select[name="mapping_type[]"]').each(function() {
                data_new =  parseInt($(this).attr('data-new'));
                html = $(this).html();
            });

            data_new = parseInt(data_new) + 1;

           var parsedResult = JSON.parse(result);

       if (parsedResult[0]) {
            var answer_depth = parsedResult[0].question_depth;
            var reason_depth = parsedResult[0].reason_depth;
            // console.log(reason_depth);
            var answer_bloom = parsedResult[0].question_bloom;
            var reason_bloom = parsedResult[0].reason_bloom;

            var answer_learning = parsedResult[0].question_learning;
        }else{
            var answer_depth = parsedResult.question_depth;
            var reason_depth = parsedResult.reason_depth;
            var answer_bloom = parsedResult.question_bloom;
            var reason_bloom = parsedResult.reason_bloom;
            var answer_learning = parsedResult.question_learning;
        }

        var SelectElement_type1 = $('select[name="mapping_type[]"][data-new=1]');
        SelectElement_type1.val(9);
        load_map_value(1,9,answer_depth);
        $('textarea[name="reasons[]"][data-reason=1]').val(reason_depth);
        $('textarea[name="learning_outcome"]').val(answer_learning);

        var mappingTypeValues = [9, 82];
        var selbox = [2];
                var mapping_type_data = html;
                if(data_new <= 2){
                var htmlcontent = '';
                    selbox.forEach(i => {

                htmlcontent += '<div class="clearfix"></div><div class="addButtonCheckbox1"><div class="row align-items-center">';
                htmlcontent += '<div class="col-md-3"><div class="form-group"><label for="topicType">Mapping Type</label><select class="load_map_value cust-select form-control mb-0" name="mapping_type[]" data-new=' + i + '>' + mapping_type_data + '</select></div></div>';
                htmlcontent += '<div class="col-md-3"><div class="form-group"><label for="topicType2">Mapping Value</label><select class="cust-select form-control mb-0" name="mapping_value[]" data-new=' + i + '><option value="">Select Mapping Value</option></select></div></div>';
                htmlcontent += '<div class="col-md-3"><div class="form-group"><label for="topicType2">Reasons</label> <textarea name="reasons[]" data-reason='+i+' id="" rows="2" class="form-control"></textarea></div></div>';

                htmlcontent += '<div class="col-md-3"><a href="javascript:void(0);" onclick="removeNewRow1();" class="btn btn-danger btn-sm"><i class="mdi mdi-minus"></i></a></div></div></div>';
            });
                $('.addButtonCheckbox1:last').after(htmlcontent);

        var SelectElement_type2 = $('select[name="mapping_type[]"][data-new=2]');
        SelectElement_type2.val(82);
        load_map_value(2,82,answer_bloom);

        // $('textarea[name="reason_2"]').val(reason_bloom);
        $('textarea[name="reasons[]"][data-reason=2]').val(reason_bloom);

        $('textarea[name="learning"]').val(answer_learning);

        // SelectElement_type3.val(1693);
        // load_map_value(3,1693,answer_learning);

            }

        },
        error: function(xhr, status, error) {
            // Handle error
            console.error('Error occurred:', error);
        }
    });

}
</script>
@include('includes.footer')
@endsection

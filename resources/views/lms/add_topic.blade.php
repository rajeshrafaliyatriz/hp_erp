{{--@include('includes.headcss')
@include('includes.header')
@include('includes.sideNavigation')--}}
@extends('layout')
@section('container')
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row bg-title">
            <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                <h4 class="page-title">
                Add Topic
                </h4>
            </div>
        </div>
        <div class="row" style=" margin-top: 25px;">
            <div class="white-box">
            <div class="panel-body">
                @if ($message = Session::get('success'))
                <div class="alert alert-success alert-block">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <strong>{{ $message }}</strong>
                </div>
                @endif
                <div class="col-lg-12 col-sm-12 col-xs-12">
                    <form action="@if (isset($data['chapter_data']))
                          {{ route('topic_master.update', $data['chapter_data']['id']) }}
                          @else
                          {{ route('topic_master.store') }}
                          @endif" enctype="multipart/form-data" method="post">
							{{ method_field("POST") }}
                            @csrf

                        <input type="hidden" id='hidchapter_id' name='hidchapter_id' value="@if(isset($data['chapter_id'])){{$data['chapter_id']}}@endif" class="form-control">
                        <div class="addButtonCheckbox">
                            <div class="col-md-3 form-group">
                                <label>Topic Name</label>
                                <input type="text" id='topic_name[]' required name="topic_name[]" class="form-control">
                            </div>
                            <input type="hidden" name="standard_id" value="{{$_REQUEST['standard_id']}}">
                            <div class="col-md-3 form-group">
                                <label>Description</label>
                                <textarea id="topic_desc[]" name="topic_desc[]" class="form-control"></textarea>
                            </div>
                            <div class="col-md-2 form-group">
                                <label>Sort Order</label>
                                <input type="text" id="topic_sort_order[]" name="topic_sort_order[]" class="form-control">
                            </div>
                            <div class="col-md-2 form-group">
                                <label>Show</label><br>
                                <input type="checkbox" checked value="1" id="topic_show_hide[]" name="topic_show_hide[]">
                            </div>

                            <div class="col-md-1 form-group">
                                <br>
                                <a href="javascript:void(0);" onclick="addNewRow();"><span class="circle circle-sm bg-success di form-control"><i class="ti-plus"></i></span></a>
                            </div>
                        </div>

                        <div class="col-md-12 form-group">
                            <center>
                                <input type="submit" name="submit" value="Save" class="btn btn-success" >
                            </center>
                        </div>

                    </form>

                </div>
            </div>
            </div>
        </div>
    </div>
</div>

@include('includes.footerJs')
<script>

function addNewRow(){
    var html = '';
    html += '<div class="clearfix"></div><div class="addButtonCheckbox">';

    html += '<div class="col-md-3 form-group"><label>Topic Name</label><input type="text" id="topic_name[]" required name="topic_name[]" class="form-control"></div>';
    html += '<div class="col-md-3 form-group"><label>Description</label><textarea id="topic_desc[]" name="topic_desc[]" class="form-control"></textarea></div>';
    html += '<div class="col-md-2 form-group"><label>Sort Order</label><br><input type="text" id="topic_sort_order[]" name="topic_sort_order[]" class="form-control"></div>';
    html += '<div class="col-md-2 form-group"><label>Show</label><br><input type="checkbox" checked value="1" id="topic_show_hide[]" name="topic_show_hide[]"></div>';

    html += '<div class="col-md-1  form-group"><a href="javascript:void(0);" onclick="removeNewRow();"><span class="circle circle-sm di form-control" style="background-color:#41b3f9;"><i class="ti-minus"></i></span></a></div></div>';
    $('.addButtonCheckbox:last').after(html);
}
function removeNewRow() {
    $(".addButtonCheckbox:last" ).remove();
}
</script>
@include('includes.footer')
@endsection

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
                Edit Topic
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
                    <form action="{{ route('topic_master.update', $data['topic_data']['id']) }}"
                    enctype="multipart/form-data" method="post">
                    {{ method_field("PUT") }}
                    @csrf

                    <div class="col-md-3 form-group">
                        <label>Topic Name</label>
                        <input type="text" id='topic_name' value="@if(isset($data['topic_data']['name'])){{$data['topic_data']['name']}}@endif" required name="topic_name" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Description</label>
                        <textarea id="topic_desc" name="topic_desc" class="form-control">@if(isset($data['topic_data']['description'])){{$data['topic_data']['description']}}@endif</textarea>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Sort Order</label>
                        <input type="text" id="topic_sort_order" name="topic_sort_order" class="form-control" value="@if(isset($data['topic_data']['topic_sort_order'])){{$data['topic_data']['topic_sort_order']}}@endif">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Show</label><br>
                        <input type="checkbox" value="1" id="topic_show_hide" name="topic_show_hide"  @if($data['topic_data']['topic_show_hide'] == 1) checked @endif>
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
@include('includes.footer')
@endsection

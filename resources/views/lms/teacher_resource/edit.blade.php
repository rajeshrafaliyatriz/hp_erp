@extends('layout')
@section('container')
<!-- Content main Section -->
<div class="content-main flex-fill">

  <div class="row">
    <div class="col-md-6">
      <h1 class="h4 mb-3">
        Edit Teacher Resource
      </h1>
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb bg-transparent p-0">
          <li class="breadcrumb-item"><a href="{{route('course_master.index')}}">LMS</a></li>
          <li class="breadcrumb-item">Teacher Resource</li>
          <li class="breadcrumb-item active" aria-current="page">Add Teacher Resource</li>
        </ol>
      </nav>
    </div>
  </div>

  <div class="card">
    @if ($message = Session::get('message'))
      <div class="alert alert-success alert-block">
        <button type="button" class="close" data-dismiss="alert">×</button>
        <strong>{{ $message }}</strong>
      </div>
      @endif
  <form action="{{ route('lms_teacherResource.update',[$data['editData']->id]) }}" method="post" enctype='multipart/form-data'
        onsubmit="return check_validation();">
        {{ method_field("PUT") }}
        @csrf
        <div class="row text-left">
          <div class="col-md-4 form-group">
            <label>Title</label> <span class="mdi mdi-asterisk" style="font-size:10px;color:red;"></span>
            <input type="text" class="form-control" name="title" id="title" required value="{{$data['editData']->title}}">
          </div>

          <div class="col-md-4 form-group">
            <label>Resource</label>
            <input type="file" name="teacher_file" id="teacher_file" class="form-control">
            @php
                if ($data['editData']->file_name != '' && $data['editData']->file_type=='link') {
                    $content_file_url = $data['editData']->file_name;
                } else {
                    $content_file_url = Storage::disk('digitalocean')->url('public'.$data['editData']->file_folder.'/'.$data['editData']->file_name);
                }
            @endphp
            <a href="{{ $content_file_url }}" target="_blank">View</a>
          </div>
        <div class="col-md-4"></div>
        </div>
        @php 
            $jsonVal = (isset($data['editData']->mapping_value) && $data['editData']->mapping_value!='') ? json_decode($data['editData']->mapping_value,true) : [];
            $j=1;
        @endphp
        @foreach($jsonVal as $jk => $jv)
        
        <div class="addButtonCheckbox" id="map_type_{{$j}}">
        <div class="row">
          <div class="col-md-4 my-2">
            <div class="form-group mb-0">
              <label for="topicType">Mapping Type</label>
                <select class="load_map_value cust-select form-control mb-0" name="mapping_type[]" data-new="update_{{$j}}">
                    <option value="">Select Mapping Type</option>
                    @if(isset($data['lms_mapping_type']))
                        @foreach($data['lms_mapping_type'] as $key => $value)
                            <option value="{{$value['id']}}" @if($jk==$value['id']) selected @endif>{{$value['name']}}</option>
                        @endforeach
                    @endif
                </select>
            </div>
          </div>
            <div class="col-md-4 my-2">
                <div class="form-group mb-0">
                <label for="topicType2">Mapping Value</label>
                    <select name="mapping_value[]" class="cust-select form-control mb-0" data-new="update_{{$j}}">
                        <option value="">Select Mapping Value</option>
                        @if(isset($data['mapVal'][$jk]))
                        @foreach($data['mapVal'][$jk] as $id=>$values)
                            <option value="{{$id}}" @if($jv==$id) selected @endif>{{$values}}</option>
                        @endforeach
                        @endif
                    </select>
                </div>
            </div>
            <div class="col-md-4 mt-0 mb-3" style="padding-top:30px;"><a href="javascript:void(0);" onclick="removeNewRowUpdate({{$j}});" class="d-inline btn btn-danger"><i class="mdi mdi-minus"></i></a></div>
            </div>
        </div>
        @php $j++; @endphp
        @endforeach

        <div class="addButtonCheckbox">
        <div class="row">
          <div class="col-md-4 my-2">
            <div class="form-group mb-0">
              <label for="topicType">Mapping Type</label>
                <select class="load_map_value cust-select form-control mb-0" name="mapping_type[]" data-new="1">
                    <option value="">Select Mapping Type</option>
                    @if(isset($data['lms_mapping_type']))
                        @foreach($data['lms_mapping_type'] as $key => $value)
                            <option value="{{$value['id']}}">{{$value['name']}}</option>
                        @endforeach
                    @endif
                </select>
            </div>
          </div>
          <div class="col-md-4 my-2">
            <div class="form-group mb-0">
              <label for="topicType2">Mapping Value</label>
                <select name="mapping_value[]" data-new="1" class="cust-select form-control mb-0">
                    <option value="">Select Mapping Value</option>
                </select>
            </div>
          </div>
          <div class="col-md-4 mt-0 mb-3" style="padding-top:30px">
            <a href="javascript:void(0);" onclick="addNewRow();" class="d-inline-block btn btn-success mr-2"><i class="mdi mdi-plus"></i></a>
            <!-- <a href="#" class="d-inline btn btn-danger btn-sm"><i class="mdi mdi-minus"></i></a> -->
          </div>
        </div>
        </div>
        <div class="row">
          @if(isset($data['data']['custom_fields']))
          @foreach($data['data']['custom_fields'] as $key => $value)

          <div class="col-md-4 form-group text-left">

            <label>{{ $value['field_label'] }}</label>

            @if($value['field_type'] == 'file')
            <input type="{{ $value['field_type'] }}" accept="image/*" id="input-file-now" @if($value['required']==1)
              required @endif name="{{ $value['field_name'] }}" class="dropify">
            @elseif($value['field_type'] == 'date')
            <div class="input-daterange input-group">
              <input type="date" class="form-control" placeholder="dd/mm/yyyy" autocomplete="off"
                id="{{ $value['field_name'] }}" @if($value['required']==1) required @endif
                name="{{ $value['field_name'] }}" class="form-control"><span class="input-group-addon"><i
                  class="icon-calender"></i></span>
            </div>

            @elseif($value['field_type'] == 'checkbox')
            <div class="checkbox-list">
              @if(isset($data['data']['data_fields'][$value['id']]))
              @foreach($data['data']['data_fields'][$value['id']] as $keyData => $valueData )
              <label class="checkbox-inline">
                <div class="checkbox checkbox-success">
                  <input type="checkbox" name="{{ $value['field_name'] }}[]" value="{{ $valueData['display_value'] }}"
                    id="{{ $valueData['display_value'] }}" @if($value['required']==1) required @endif>
                  <label for="{{ $valueData['display_value'] }}">{{ $valueData['display_text'] }}</label>
                </div>
              </label>
              @endforeach
              @endif
            </div>

            @elseif($value['field_type'] == 'dropdown')
            <select name="{{ $value['field_name'] }}" class="form-control" @if($value['required']==1) required @endif
              id="{{ $value['field_name'] }}">
              <option value=""> SELECT {{ strtoupper($value['field_label']) }} </option>
              @if(isset($data['data']['data_fields'][$value['id']]))
              @foreach($data['data']['data_fields'][$value['id']] as $keyData => $valueData)
              <option value="{{ $valueData['display_value'] }}"> {{ $valueData['display_text'] }} </option>
              @endforeach
              @endif
            </select>

            @elseif($value['field_type'] == 'textarea')
            <textarea id="{{ $value['field_name'] }}" class="form-control" @if($value['required']==1) required @endif
              name="{{ $value['field_name'] }}" placeholder="{{ $value['field_message'] }}">
                                </textarea>

            @else
            <input type="{{ $value['field_type'] }}" id="{{ $value['field_name'] }}"
              placeholder="{{ $value['field_message'] }}" @if($value['required']==1) required @endif
              name="{{ $value['field_name'] }}" class="form-control">
            @endif
          </div>
          @endforeach
          @endif

          <div class="col-md-3 form-group mt-4">
            <input type="submit" name="submit" value="Save" class="btn btn-success">
          </div>
        </div>
      </form>

  </div>
  </div>
@include('includes.lmsfooterJs')
<script>
    function addNewRow() {
    $('select[name="mapping_type[]"]').each(function () {
      data_new = parseInt($(this).attr('data-new'));
      html = $(this).html();
    });
    data_new = parseInt(data_new) + 1;

    var mapping_type_data = html;//$('#mapping_type:first').html();
    var htmlcontent = '';
    htmlcontent += '<div class="clearfix"></div><div class="addButtonCheckbox" style="display: flex; margin-right: -15px; margin-left: -15px; flex-wrap: wrap;">';

    htmlcontent += '<div class="col-md-4 my-2"><div class="form-group mb-0"><label for="topicType">Mapping Type</label><select class="load_map_value form-control cust-select" name="mapping_type[]" data-new=' + data_new + '>' + mapping_type_data + '</select></div></div>';
    htmlcontent += '<div class="col-md-4 my-2"><div class="form-group mb-0"><label for="topicType2">Mapping Value</label><select class="form-control cust-select" name="mapping_value[]" data-new=' + data_new + '><option>Select Mapping Value</option></select></div></div>';
    htmlcontent += '<div class="col-md-4 mt-0 mb-3" style="padding-top:30px;"><a href="javascript:void(0);" onclick="removeNewRow();" class="d-inline btn btn-danger"><i class="mdi mdi-minus"></i></a></div></div>';

    $('.addButtonCheckbox:last').after(htmlcontent);
  }

  function removeNewRow() {
    $(".addButtonCheckbox:last").remove();
  }

  function removeNewRowUpdate(dataId){
    $("#map_type_"+dataId).remove();
  }

   $(document).on('change', '.load_map_value', function () {
    var mapping_type = $(this).val();
    var data_new = $(this).attr('data-new');
    // alert(mapping_type);
    // alert(data_new);

    var path = "{{ route('ajax_LMS_MappingValue') }}";
    //$('#mapping_value').find('option').remove().end();
    $.ajax({
      url: path,
      data: 'mapping_type=' + mapping_type,
      success: function (result) {
        //var e = $('#mapping_value[data-new='+data_new+']');
        var e = $('select[name="mapping_value[]"][data-new=' + data_new + ']');
        $(e).find('option').remove().end();
        for (var i = 0; i < result.length; i++) {
          $(e).append($("<option></option>").val(result[i]['id']).html(result[i]['name']));
          //$("#mapping_value[]").append($("<option></option>").val(result[i]['id']).html(result[i]['name']));
        }
      }
    });
  });
</script>
@include('includes.footer')
@endsection
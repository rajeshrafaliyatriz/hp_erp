@extends('layout')
@section('content')
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row bg-title">
            <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                <h4 class="page-title">Manage {{$data['data']['name']}}</h4>
            </div>
        </div>
        <div class="card">
            @if ($sessionData = Session::get('data'))
            <div class="alert @if($sessionData['status']==1) alert-success @else alert-danger @endif alert-block">
                <button type="button" class="close" data-dismiss="alert">×</button>
                <strong>{{ $sessionData['message'] }}</strong>
            </div>
        @endif
            <form action="{{ route('custom_module_crud.store', $data['data']['id']) }}" enctype="multipart/form-data"
                  method="post">
                @csrf

                <div class="other col-lg-3 col-sm-3 col-xs-3">
                    <a href="{{ route('custom_module_crud.index',$data['data']['id']) }}" class="btn btn-info add-new">
                        Back </a>
                </div>
                <div class="row mt-3">

                    @php 
                    $mt = "mt-2";
                    @endphp 

                    @if(isset($data['data']['helper_function']) && $data['data']['helper_function'] != null)
                    @php 
                    $grd = isset($data['data']['view']['grade']) ? $data['data']['view']['grade'] : null;
                    $std = isset($data['data']['view']['standard']) ? $data['data']['view']['standard'] : null;
                    $div = isset($data['data']['view']['division']) ? $data['data']['view']['division'] : null;
                    $term = isset($data['data']['view']['term']) ? $data['data']['view']['term'] : null;
                    $dep_id = isset($data['data']['view']['department_id']) ? $data['data']['view']['department_id'] : null;
                    $emp_id = isset($data['data']['view']['emp_id']) ? $data['data']['view']['emp_id'] : null;
                    $mt = "";
                // 'Grade,Standard,Division','Grade,Standard,Division,Student', 'Grade,Standard','Grade,Standard,Student', 'Term,Grade,Standard,Division', 'Department,Employee'
                    @endphp
                        @if($data['data']['helper_function'] == 'Grade,Standard,Division')
                            {{ App\Helpers\SearchChain('4','single','grade,std,div',$grd,$std,$div)}}
                        @endif

                        @if($data['data']['helper_function'] == 'Grade,Standard,Division,Student')
                        {{ App\Helpers\SearchChain('4','single','grade,std,div',$grd,$std,$div)}}
                        <div class="col-md-4">
                            <label for="">Select Student</label>
                            <select name="student_id" id="DvisionStudent" class="form-control">
                                <option value="">Please Select Other Selects first</option>
                            </select>
                        </div>
                        @endif

                        @if($data['data']['helper_function'] == 'Term,Grade,Standard,Division')
                            {{ App\Helpers\TermDD($term) }}
                            {{ App\Helpers\SearchChain('4','single','grade,std,div',$grd,$std,$div)}}
                        @endif
                        @if($data['data']['helper_function'] == 'Grade,Standard')
                        {{ App\Helpers\SearchChain('4','single','grade,std',$grd,$std)}}
                         @endif

                         @if($data['data']['helper_function'] == 'Grade,Standard,Student')
                         {{ App\Helpers\SearchChain('4','single','grade,std',$grd,$std)}}
                         <div class="col-md-4">
                            <label for="">Select Student</label>
                            <select name="student_id" id="StandardStudent" class="form-control">
                                <option value="">Please Select Other Selects first</option>
                            </select>
                        </div>
                          @endif

                          @if($data['data']['helper_function'] == 'Department,Employee')
                            {!! App\Helpers\HrmsDepartments('4','',$dep_id,"",$emp_id) !!}
                           @endif

                    @endif
                    @if(isset($data['data']['syear_wise']) && $data['data']['syear_wise']==1)
                            <input type="hidden"  name="syear" value="{{session()->get('syear')}}">
                    @endif

                    @foreach($data['data']['columns'] as $column)
                            <?php
                            $fieldVal = json_decode($column['field_value'], true);
                            ?>
                        @if($column['column_name'] == 'id')
                            @continue
                        @endif
                        @if(!in_array($column['column_name'], ['syear','term', 'grade', 'standard', 'division', 'department_id', 'emp_id','student_id']))
                        <div class="col-md-4 {{$mt}}">
                            <label>{{ ucwords(str_replace('_', ' ', $column['column_name'])) }} <span style="color: red">{{($column['not_null']==1) ? '*' : ''}}</span></label>
                            @if($column['field_type'] == 'File')
                                <input type="file" id="{{$column['column_name']}}"
                                       {{$data['data']['view'][$column['column_name']] ? "hidden":"" }} name="{{$column['column_name']}}"
                                       class="form-control" value="{{$data['data']['view'][$column['column_name']]}}">
                                <input type="file" id="{{$column['column_name']}}"
                                       {{$data['data']['view'][$column['column_name']] ? "":"hidden" }} name="new_{{$column['column_name']}}"
                                       class="form-control" value="{{$data['data']['view'][$column['column_name']]}}">
                                @if ($data['data']['view']['id'] > 0)
                                    <a href="{{asset('images/'.$data['data']['view'][$column['column_name']])}}"
                                       target="_blank">View File</a>
                                @endif
                            @elseif($column['field_type'] == 'checkbox')
                                @foreach($fieldVal as $val)
                                        <?php
                                        $checkboxVal = json_decode($data['data']['view'][$column['column_name']]);
                                        ?>
                                    @if(is_array($checkboxVal) && in_array($val, $checkboxVal))
                                        <span style="display: block"> {{$val}} <input type="checkbox" checked
                                                                                      name="{{$column['column_name']}}[]"
                                                                                      value="{{$val}}"></span>
                                    @else
                                        <span style="display: block"> {{$val}} <input type="checkbox"
                                                                                      name="{{$column['column_name']}}[]"
                                                                                      value="{{$val}}"></span>
                                    @endif
                                @endforeach
                            @elseif($column['field_type'] == 'drop-down')
                                <select class="form-control" id="{{$column['column_name']}}"
                                        name="{{$column['column_name']}}">0
                                    <option value=""
                                    >Select {{ucwords(str_replace('_', ' ', $column['column_name']))}}</option>
                                    @foreach($fieldVal as $val)
                                        @if ($data['data']['view'][$column['column_name']] == $val)
                                            <option value="{{$val}}" selected
                                            >{{$val}}</option>
                                        @else
                                            <option value="{{$val}}"
                                            >{{$val}}</option>
                                        @endif
                                    @endforeach

                                </select>

                            @elseif($column['field_type'] == 'radio-button')
                                @foreach($fieldVal as $val)
                                    @if ($data['data']['view'][$column['column_name']] == $val)
                                    <span style="display: block"> {{$val}} <input type="radio" checked
                                                                                  name="{{$column['column_name']}}"
                                                                                  value="{{$val}}"></span>
                                    @else
                                        <span style="display: block"> {{$val}} <input type="radio"
                                                                                      name="{{$column['column_name']}}"
                                                                                      value="{{$val}}"></span>
                                    @endif
                                @endforeach

                            @elseif($column['column_name'] == 'academic_section')
                                <select class="form-control" id="{{$column['column_name']}}"
                                        name="{{$column['column_name']}}">
                                    @foreach($data['data']['academic_section'] as $academic_section)
                                        @if ($data['data']['view'][$column['column_name']] == $academic_section['id'])
                                            <option value="{{$academic_section['id']}}"
                                                    selected>{{$academic_section['title']}}
                                                - {{$academic_section['short_name']}}
                                                - {{$academic_section['medium']}}</option>
                                        @else
                                            <option value="{{$academic_section['id']}}">{{$academic_section['title']}}
                                                - {{$academic_section['short_name']}}
                                                - {{$academic_section['medium']}}</option>
                                        @endif
                                    @endforeach

                                </select>
                            @elseif($column['column_name'] == 'Division')
                                <select class="form-control" id="{{$column['column_name']}}"
                                        name="{{$column['column_name']}}">
                                    @foreach($data['data']['division'] as $division)
                                        @if ($data['data']['view'][$column['column_name']] == $division['id'])
                                            <option value="{{$division['id']}}" selected>{{$division['name']}}</option>
                                        @else
                                            <option value="{{$division['id']}}">{{$division['name']}}</option>
                                        @endif
                                    @endforeach

                                </select>
                            @elseif($column['column_name'] == 'Standard')
                                <select class="form-control" id="{{$column['column_name']}}"
                                        name="{{$column['column_name']}}">
                                    @foreach($data['data']['standard'] as $standard)
                                        @if ($data['data']['view'][$column['column_name']] == $standard['id'])
                                            <option value="{{$standard['id']}}" selected>{{$standard['name']}}</option>
                                        @else
                                            <option value="{{$standard['id']}}">{{$standard['name']}}</option>
                                        @endif
                                    @endforeach
                                </select>
                            @elseif ($column['field_type'] == "date")
                                <input type="text" id="{{$column['column_name']}}"
                                       name="{{$column['column_name']}}" class="form-control mydatepicker"
                                       value="{{ $data['data']['view'][$column['column_name']] }}">
                        <!-- added by uma on 10-04-2025 -->
                            @elseif ($column['field_type'] == "text-area")
                                <textarea id="{{$column['column_name']}}"
                                       name="{{$column['column_name']}}" class="form-control resizableVertical">{{$data['data']['view'][$column['column_name']]}}</textarea>
                            @elseif ($column['field_type'] == "mobile")
                            <input type="text" id="{{$column['column_name']}}" pattern="[1-9]{1}[0-9]{9}" name="{{$column['column_name']}}" class="form-control" value="{{$data['data']['view'][$column['column_name']]}}">
                            @elseif ($column['field_type'] == "email")
                                <input type="email" id="{{$column['column_name']}}" name="{{$column['column_name']}}" class="form-control" value="{{$data['data']['view'][$column['column_name']]}}">
                           @elseif ($column['field_type'] == "number")
                                <input type="number" id="{{$column['column_name']}}" name="{{$column['column_name']}}" class="form-control" value="{{$data['data']['view'][$column['column_name']]}}">
                        <!-- added by uma on 10-04-2025 end -->
                            @else
                                <input type="text" id="{{$column['column_name']}}" name="{{$column['column_name']}}" class="form-control" value="{{$data['data']['view'][$column['column_name']]}}">
                            @endif
                            @error($column['column_name'])
                            <div class="error" style="color: red">{{ $message }}</div>
                            @enderror
                        </div>
                        @endif

                    @endforeach
                    <input type="text" hidden name="view_id" value="{{$data['data']['view']['id']}}"
                           class="btn btn-success">
                    {{--<input type="hidden" value="{{$data['column_id']}}" name="col_id">--}}
                </div>
                <div class="row mt-4">
                    <div class="form-group align-center">
                        <center>
                            <input type="submit" name="submit" id="Submit" value="Submit" class="btn btn-success">

                        </center>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="{{ asset("/plugins/bower_components/datatables/datatables.min.js") }}"></script>
<script>
    $(document).ready(function () {
        $('#example').DataTable();
        $('#division').on('change',function(){
            var grade = $('#grade').val();
            var std = $('#standard').val();
            var div = $(this).val();
            getStudentLists('DvisionStudent',grade,std,div);
        })
        $('#standard').on('change',function(){
            var grade = $('#grade').val();
            var std = $(this).val();
            getStudentLists('StandardStudent',grade,std,'');
        })

        @if(isset($data['data']['helper_function']) && $data['data']['helper_function'] != null)
            @if($data['data']['helper_function'] == 'Grade,Standard,Division,Student' && isset($data['data']['view']))
                @php
                    $grade = $data['data']['view']['grade'] ?? '';
                    $standard = $data['data']['view']['standard'] ?? '';
                    $division = $data['data']['view']['division'] ?? '';
                    $student_id = $data['data']['view']['student_id'] ?? '';
                @endphp
                getStudentLists('DvisionStudent', "{{ $grade }}", "{{ $standard }}", "{{ $division }}", "{{ $student_id }}");
            @endif
            @if($data['data']['helper_function'] == 'Grade,Standard,Student' && isset($data['data']['view']))
                getStudentLists(
                    'StandardStudent',
                    "{{ isset($data['data']['view']['grade']) ? $data['data']['view']['grade'] : '' }}",
                    "{{ isset($data['data']['view']['standard']) ? $data['data']['view']['standard'] : '' }}",
                    "",
                    "{{ isset($data['data']['view']['student_id']) ? $data['data']['view']['student_id'] : '' }}"
                );
            @endif
        @endif
    });

    function getStudentLists(selectName,grade,std,div='',selval=''){
        $.ajax({
            url: "{{ route('studentLists') }}",
            type: "GET",
            data: {
                grade: grade,
                std: std,
                div: div,
                _token: '{{ csrf_token() }}'
            },
            success: function (data) {
                $('#'+selectName).empty();
                $('#'+selectName).append(`<option value="">Select Student</option>`);
                $.each(data, function (key, value) {
                    if (value.id && value.first_name) {
                        var selected = (selval == value.id) ? 'selected' : '';
                        $('#'+selectName).append(`<option value="${value.id}" ${selected}>${value.first_name || '-'} ${value.middle || '-'} ${value.last_name || '-'} (${value.enrollment_no || '-'}) </option>`);
                    }
                });
            }
        });
    }

</script>

@endsection
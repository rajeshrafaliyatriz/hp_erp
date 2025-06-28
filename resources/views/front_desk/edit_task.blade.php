@extends('layout')
@section('content')
<link rel="stylesheet" href="../../../plugins/bower_components/dropify/dist/css/dropify.min.css">
<link href="/plugins/bower_components/clockpicker/dist/jquery-clockpicker.min.css" rel="stylesheet">

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row bg-title">
            <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                <h4 class="page-title">Edit Tasks</h4> </div>
        </div>        
        <div class="card"> 
            <div class="row">
                <!-- @TODO: Create a saperate tmplate for messages and include in all tempate -->
                    @if ($sessionData = Session::get('data'))
                    @if($sessionData['status_code'] == 1)
                    <div class="alert alert-success alert-block">
                    @else
                    <div class="alert alert-danger alert-block">
                    @endif
                        <button type="button" class="close" data-dismiss="alert">×</button>
                        <strong>{{ $sessionData['message'] }}</strong>
                    </div>
                    @endif

                <div class="col-lg-12 col-sm-12 col-xs-12">
                    <form action="{{ route('task.update',$data['id']) }}" enctype="multipart/form-data" method="post" id="emailForm" novalidate>

                        {{ method_field("PUT") }}
                        @csrf
                        @php 
                            $taskType = ['Daily Task','Weekly Task','Monthly Task','Yearly Task'];
                        @endphp
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>Title </label>
                                <input type="text" id='task_title' value="@if(isset($data['task_title'])){{ $data['task_title'] }}@endif" required name='task_title' class="form-control">
                            </div>

                            <div class="col-md-4 form-group">
                                <label>Description </label>
                                <input type="text" id='task_description' value="@if(isset($data['task_description'])) {{$data['task_description']}}@endif"  required name='task_description' class="form-control">
                            </div>

                            <div class="col-md-4 form-group">
                                <label>Date </label>
                                <input type="text" required name='TASK_DATE' value="@if(isset($data['task_date'])){{ $data['task_date'] }}@endif" class="form-control mydatepicker">
                            </div>

                            <div class="col-md-4 form-group">
                                <label>TASK ALLOCATED </label>                                
                                <select id="task_allocated" name="task_allocated" class="form-control" readonly>
                                    @if(isset($data))
                                        <option value="{{$data['task_allocated']}}"> {{$data['ALLOCATOR']}} </option>
                                    @endif
                                </select>
                            </div>

                            <div class="col-md-4 form-group">
                                <label>TASK ALLOCATED TO </label>                                
                                <select id="task_allocated_to" name="task_allocated_to" class="form-control" readonly>
                                    @if(isset($data))
                                        <option value="{{$data['task_allocated_to']}}"> {{$data['ALLOCATED_TO']}} </option>
                                    @endif
                                </select>
                            </div> 
                             <!-- add KRA -->
                             <div class="col-md-4  form-group">
                                <label for="task">Add KRA</label>
                                <input type="text" name="kra" id="kra" class="form-control" value="@if(isset($data['kra'])){{ $data['kra'] }}@endif" autocomplete="off" >
                            </div>
                            <!--add KPA -->
                            <div class="col-md-4  form-group">
                                <label for="task">Add KPA</label>
                                <input type="text" name="kpa" id="kpa" class="form-control" value="@if(isset($data['kpa'])){{ $data['kpa'] }}@endif" autocomplete="off" >
                            </div>
                            <!-- add Type -->
                            <div class="col-md-4  form-group">
                                <label for="task">Add Type</label>
                                <select name="selType" id="selType" class="form-control selType">
                                    <option value="">Select Type</option>
                                    @foreach($taskType as $key=>$value)
                                    <option value="{{$value}}" @if(isset($data['task_type']) && $data['task_type'] == $value) selected @endif >{{$value}}</option>
                                    @endforeach
                                </select>
                            </div>
                            <!-- add manageby -->
                            <div class="col-md-4 form-group">
                                <label>Manage By </label>                                
                                <select id="manageby" name="manageby" class="form-control" readonly>
                                    @if(isset($data))
                                        <option value="{{$data['manageby']}}"> {{$data['manageby']}} </option>
                                    @endif
                                </select>
                            </div> 
                            <div class="col-md-4 form-group">
                                <label>Skills </label>
                                <textarea class="form-control" name="skills" id="skills" @if($data['task_allocated_to']==session()->get('user_id')) readonly  @endif>{{$data['required_skills']}}</textarea>
                            </div>
                            <!-- <div class="col-md-4 form-group">
                                <label>User </label>
                                <input type="text" id='allocated_to' value="@if(isset($data['allocated_to'])){{ $data['allocated_to'] . ' - '. $data['allocated_to'] }}@endif" list="userAllocatedList" name="allocated_to" class="form-control">
                                <datalist id="userAllocatedList">
                                    @if(isset($userList))
                                        @foreach($userList as $key => $value)
                                            <option value="{{$value['first_name'].' '.$value['last_name'] . ' - '. $value['id']}}"> {{$value['first_name']." ".$value['last_name']}} </option>
                                        @endforeach
                                    @endif
                                </datalist>
                            </div> -->
                           
                            <div class="col-md-4">
                                <label for="">Observation Points</label>
                                <textarea class="form-control" name="observation_point" id="observation_point" @if($data['task_allocated_to']==session()->get('user_id')) readonly  @endif>{{$data['observation_point']}}</textarea>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Task Status </label>
                                <select name='STATUS' class="form-control">
                                    <option value=""> Select Task Status </option>
                                    @foreach($taskStatus as $key => $value)
                                        <option value="{{$value}}" @if(isset($data['status']) && $data['status'] == $value) selected="selected"  @endif >{{$value}}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="input-file-now">Task Attachment</label>
                                <input type="file" @if(isset($data['TASK_ATTACHMENT'])) data-default-file="/storage/frontdesk/{{ $data['TASK_ATTACHMENT'] }}" @endif name="TASK_ATTACHMENT" id="input-file-now" class="dropify" />
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Reply </label>
                                <textarea type="text" id='reply' required name='reply' class="form-control">{{ $data['reply'] }}
                                </textarea>
                            </div>
                            
                            <div class="col-md-12 form-group">
                                    <input type="submit" name="submit" value="Update" class="btn btn-success" >
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>


<script type="text/javascript">
   
</script>
<script src="../../../plugins/bower_components/dropify/dist/js/dropify.min.js"></script>
    <script>
    $(document).ready(function() {
        // Basic
        $('.dropify').dropify();
        // Translated
        $('.dropify-fr').dropify({
            messages: {
                default: 'Glissez-déposez un fichier ici ou cliquez',
                replace: 'Glissez-déposez un fichier ou cliquez pour remplacer',
                remove: 'Supprimer',
                error: 'Désolé, le fichier trop volumineux'
            }
        });
        // Used events
        var drEvent = $('#input-file-events').dropify();
        drEvent.on('dropify.beforeClear', function(event, element) {
            return confirm("Do you really want to delete \"" + element.file.name + "\" ?");
        });
        drEvent.on('dropify.afterClear', function(event, element) {
            alert('File deleted');
        });
        drEvent.on('dropify.errors', function(event, element) {
            console.log('Has Errors');
        });
        var drDestroy = $('#input-file-to-destroy').dropify();
        drDestroy = drDestroy.data('dropify')
        $('#toggleDropify').on('click', function(e) {
            e.preventDefault();
            if (drDestroy.isDropified()) {
                drDestroy.destroy();
            } else {
                drDestroy.init();
            }
        })
    });
    </script>

@endsection

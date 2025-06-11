@extends('layout')
@section('content')

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row bg-title">
            <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                <h4 class="page-title">User</h4>
            </div>
        </div>        
        <div class="card">               
            @if ($sessionData = Session::get('data'))
            <div class="alert alert-success alert-block">
                <button type="button" class="close" data-dismiss="alert">×</button>
                <strong>{{ $sessionData['message'] }}</strong>
            </div>
            @endif
            <div class="row">  
                @if(in_array(session()->get('user_profile_name'),["Admin","Super Admin"]))              
                <div class="col-lg-3 col-sm-3 col-xs-3">
                    <a href="{{ route('add_user.create') }}" class="btn btn-info add-new"><i class="fa fa-plus"></i> Add New User </a>
                </div>
                @endif
                <div class="col-lg-12 col-sm-12 col-xs-12">
                    <div class="table-responsive">
                        <table id="example" class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Id</th>
                                    <th>User Name</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Mobile</th>
                                    <th>User Profile</th>
                                    <th>Active Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            @php
                            $j=1;
                            @endphp
                                @foreach($data['data'] as $key => $data)
                                <tr>    
                                    <td>{{$j}}</td>
                                    <td>{{$data->user_name}}</td>
                                    <td>{{$data->first_name}}</td>
                                    <td>{{$data->email}}</td>  
                                    <td>{{$data->mobile}}</td> 
                                    <td>{{$data->profile_name}}</td> 
                                    <td>{{$data->status}}</td> 
                                    <td>
                                        <div class="d-inline">
                                            <a href="{{ route('add_user.edit',$data->id)}}" class="btn btn-info btn-outline"><i class="ti-pencil-alt"></i></a>
                                        </div>
                                        @if(in_array(session()->get('user_profile_name'),["Admin","Super Admin"]))              
                                        <form class="d-inline" action="{{ route('add_user.destroy', $data->id)}}" method="post">
                                        @csrf
                                        @method('DELETE')
                                        <button onclick="return confirmDelete();" type="submit" class="btn btn-info btn-outline-danger"><i class="ti-trash"></i></button>
                                        </form>
                                        @endif
                                    </td>  
                                </tr>
                                @php
                            $j++;
                            @endphp
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>        
    </div>
</div>

<script>
$(document).ready(function () {
    $('#example').DataTable();
});
function confirmDelete() {
    // This is the native browser confirmation dialog.
    // WARNING: This is generally considered bad UX and is NOT allowed in Canvas/Immersive documents.
    // It blocks the UI and cannot be styled.
    return confirm("Are you sure you want to Inactive this user?");
}
</script>

@endsection

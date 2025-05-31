@extends('layout')
@section('content')
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row bg-title">
            <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                <h4 class="page-title">Custom Modules</h4>
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
                <div class="col-lg-3 col-sm-3 col-xs-3">
                    <a href="{{ route('custom_module_table.create') }}" class="btn btn-info add-new"><i class="fa fa-plus"></i> Add Module </a>
                </div>
                <div class="col-lg-12 col-sm-12 col-xs-12">
                    <div class="table-responsive">
                        <table id="example" class="table table-striped">
                            <thead>
                            <tr>
                                <th>Sr No.</th>
                                <th>Table Name</th>
                                {{-- <th>Client Id</th>
                                 <th>Sub Institute Id</th>--}}
                                {{--<th>Status</th>--}}
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @php
                                $j=1;
                            @endphp
                            @foreach($data['data'] as $key => $data)
                                <tr>
                                    <td>{{$key+1}}</td>
                                    <td>{{$data->table_name}}</td>
                                    {{--<td>{{$data->client_id}}</td>
                                    <td>{{$data->sub_institute_id}}</td>--}}
                                    <td>
                                        <div class="d-inline">
                                            <a href="{{ url('custom-module/table-column-create/'.$data->id)}}" class="btn btn-info add-new">+</a>
                                            <a href="{{ url('custom-module/table-create/'.$data->id)}}" class="btn btn-info btn-outline"><i class="fa fa-pencil" aria-hidden="true"></i></a>
                                            @if ($data->is_exists)
                                            <a href="{{ url('custom-module/table?id='.$data->id)}}" class="btn btn-info btn-outline"><i class="fa fa-eye" aria-hidden="true"></i></a>
                                            @endif
                                        </div>
                                        <form class="d-inline" action="{{ route('custom_module_table.delete', $data->id)}}" method="post">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-info btn-outline-danger" onclick="return confirm('Are you sure you want to delete this record?');"><i class="fa fa-trash" aria-hidden="true"></i></button>
                                        </form>
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

<script src="{{ asset("/plugins/bower_components/datatables/datatables.min.js") }}"></script>
<script>
     $(document).ready(function () {
        $('#example').DataTable({
            select: true,
            lengthMenu: [[100, 500, 1000, -1], ['100', '500', '1000', 'Show All']],
        });
    });
</script>
@endsection
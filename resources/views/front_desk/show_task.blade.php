@extends('layout')
@section('content')
    <div id="page-wrapper">
        <div class="container-fluid">
            <div class="row bg-title">
                <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                    <h4 class="page-title">Tasks</h4>
                </div>
            </div>
            <div class="card">
                <div class="row">
                    <div class="col-lg-12 col-sm-12 col-xs-12">
                        @if ($sessionData = Session::get('data'))
                            @if ($sessionData['status_code'] == 1)
                                <div class="alert alert-success alert-block">
                                @else
                                    <div class="alert alert-danger alert-block">
                            @endif
                            <button type="button" class="close" data-dismiss="alert">×</button>
                            <strong>{{ $sessionData['message'] }}</strong>
                    </div>
                    @endif
                    <form action="{{ route('task.index') }}" enctype="multipart/form-data">
                        @csrf
                        @php
                            $taskType = ['Daily Task', 'Weekly Task', 'Monthly Task', 'Yearly Task'];
                        @endphp
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>From Date </label>
                                <input type="text" id='from_date'
                                    value="@if (isset($data['from_date'])) {{ $data['from_date'] }} @endif"
                                    name='from_date' class="form-control mydatepicker">
                            </div>

                            <div class="col-md-4 form-group">
                                <label>To Date </label>
                                <input type="text" id='to_date'
                                    value="@if (isset($data['to_date'])) {{ $data['to_date'] }} @endif" name='to_date'
                                    class="form-control mydatepicker">
                            </div>
                            <div class="col-md-4  form-group">
                                <label for="task">Search Type</label>
                                <select name="taskType" id="taskType" class="form-control taskType">
                                    <option value="">Select Type</option>
                                    @foreach ($taskType as $key => $value)
                                        <option value="{{ $value }}"
                                            @if (isset($data['taskType']) && $data['taskType'] == $value) selected @endif>{{ $value }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-12 form-group mt-4">
                                <center>
                                    <input type="submit" name="submit" value="Search" class="btn btn-success">
                                    <center>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="row">

                <div class="col-lg-3 col-sm-3 col-xs-3">
                    <a href="{{ route('institute_detail.index') }}#section-linemove-2#section-dep-2" class="btn btn-primary add-new"
                        target="_blank"><i class="fa fa-plus"></i> Add New Tasks</a>
                    <a class="btn btn-info add-new ml-2" data-toggle="modal" data-target="#exampleModal">Check List</a>
                </div>

                <div class="col-lg-9 col-sm-3 col-xs-3 text-right">
                    <a class="btn btn-outline-secondary" href="{{ route('lmsActivityStream.index') }}" target="_blank"
                        class="nav-link">
                        <span>Activity Stream</span>
                    </a>
                </div>

                <br><br><br>
                <div class="col-lg-12 col-sm-12 col-xs-12">
                    <div class="table-responsive">
                        <table id="example" class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Sr No</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>KRA</th>
                                    <th>KPA</th>
                                    <th>Type</th>
                                    <th>Skills</th>
                                    <th>Date</th>
                                    <th>Allocator</th>
                                    <th>Allocated To</th>
                                    <th>Observation By</th>
                                    <th>Observation Points</th>
                                    <th>Reply</th>
                                    <th>Status</th>
                                    <th>Approved By</th>
                                    <th>Attachment</th>
                                    <th>Approved Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $j = 1;
                                @endphp
                                @if (isset($data['data']))
                                    @foreach ($data['data'] as $key => $value)
                                        <tr>
                                            <td>{{ $j }}</td>
                                            <td>{{ $value->task_title }}</td>
                                            <td>{{ $value->task_description }}</td>
                                            <td>{{ $value->kra }}</td>
                                            <td>{{ $value->kpa }}</td>
                                            <td>{{ $value->task_type }}</td>
                                            <td>{{ $value->required_skills }}</td>
                                            <td>{{ $value->task_date ? date('d-m-Y', strtotime($value->task_date)) : '-' }}
                                            </td>
                                            <td>{{ $value->ALLOCATOR }}</td>
                                            <td>{{ $value->ALLOCATED_TO }}</td>
                                            <td>{{ $value->manageby }}</td>
                                            <td>{{ $value->observation_point }}</td>
                                            <td>{{ $value->reply }}</td>
                                            <td>{{ $value->status }}</td>
                                            <td>
                                                @if ($value->approved_by == '')
                                                    -
                                                @else
                                                    {{ $value->approved_by }}
                                                @endif
                                            </td>
                                            <td>
                                                <a target="blank"
                                                    href="/storage/frontdesk/{{ $value->task_attachment }}">{{ $value->task_attachment }}</a>
                                            </td>
                                            <td>{{ $value->approved_on ? date('d-m-Y H:i:s', strtotime($value->approved_on)) : '-' }}
                                            </td>
                                            <td>
                                                <div class="d-inline">
                                                    <a href="{{ route('task.edit', $value->id) }}"
                                                        class="btn btn-info btn-outline"><i class="ti-pencil-alt"></i></a>
                                                </div>
                                                <form action="{{ route('task.destroy', $value->id) }}" method="post"
                                                    class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" onclick="return confirmDelete();"
                                                        class="btn btn-outline-danger"><i class="ti-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        @php
                                            $j++;
                                        @endphp
                                    @endforeach
                                @endif

                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Today's Check List</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Sr No</th>
                                <th>Task</th>
                                <th>Status</th>
                                <th class="text-left">Reply</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['checkList'] as $k => $value)
                                <tr>
                                    <td>{{ $k + 1 }}</td>
                                    <td>{{ $value->task_title }}</td>
                                    <td>{{ $value->status }}</td>
                                    <td>{{ $value->reply }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            var table = $('#example').DataTable({
                select: true,
                lengthMenu: [
                    [100, 500, 1000, -1],
                    ['100', '500', '1000', 'Show All']
                ],
                dom: 'Bfrtip',
                buttons: [{
                        extend: 'pdfHtml5',
                        title: 'Teacher Resources Report',
                        orientation: 'landscape',
                        pageSize: 'LEGAL',
                        pageSize: 'A0',
                        exportOptions: {
                            columns: ':visible'
                        },
                    },
                    {
                        extend: 'csv',
                        text: ' CSV',
                        title: 'Teacher Resources Report'
                    },
                    {
                        extend: 'excel',
                        text: ' EXCEL',
                        title: 'Teacher Resources Report'
                    },
                    {
                        extend: 'print',
                        text: ' PRINT',
                        title: 'Teacher Resources Report'
                    },
                    'pageLength'
                ],
            });

            $('#example thead tr').clone(true).appendTo('#example thead');
            $('#example thead tr:eq(1) th').each(function(i) {
                var title = $(this).text();
                $(this).html('<input type="text" placeholder="Search ' + title + '" />');

                $('input', this).on('keyup change', function() {
                    if (table.column(i).search() !== this.value) {
                        table
                            .column(i)
                            .search(this.value)
                            .draw();
                    }
                });
            });
        });
    </script>
@endsection

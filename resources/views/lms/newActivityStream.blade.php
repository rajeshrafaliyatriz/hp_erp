@extends('layout')
@section('content')
    <link rel="stylesheet" href="{{ asset('/activity_stream_asset/styles.css') }}">
    <div class="content-main flex-fill">
        <div class="container-fluid mb-5">
            <div class="row">
                <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12 mb-4">
                    <h1 class="h4 mb-3">Activity Stream</h1>

                </div>
            </div>
            <!-- upcoming start  -->
            <div class="dropdown-container">
                <div style="width: 100%">
                    <div id="dropdown-1" class="dropdownn">
                        <!-- upcoming head  -->
                        <div type="button" id="dropdownMenuButton" style="padding: 12px 30px">
                            <h5 class="d-inline mr-2 sub-p"><b>Upcoming</b></h5>
                            <img class="arrow-down"
                                src="{{ asset('/activity_stream_asset/arrow-down-sign-to-navigate.png') }}"
                                height="12" />
                        </div>
                        <!-- upcoming body  -->
                        <div class="dropdown-item-list d-none">

                            @if (isset($data['upcoming']['eventCalender']) && !empty($data['upcoming']['eventCalender']))
                                @foreach ($data['upcoming']['eventCalender'] as $key => $value)
                                    @php
                                        $startTime = $value->created_at
                                            ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                            : '-';
                                        $startDate = $value->school_date
                                            ? \Carbon\Carbon::parse($value->school_date)->format('d-m-Y')
                                            : '-';
                                    @endphp
                                    <div class="dropdown-itemm">
                                        <div class="d-flex flex-column">
                                            {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Events & Calender</a>
                                            </div>
                                            <div class="sub-heading">{{ $value->task_title }} on {{ $startDate }}</div>
                                            <div class="link">
                                                <a href="" class="startDate">{{ $startDate }}</a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                            @if (isset($data['upcoming']['announcementNotice']) && !empty($data['upcoming']['announcementNotice']))
                                @foreach ($data['upcoming']['announcementNotice'] as $key => $value)
                                    @php
                                        $startTime = $value->created_at
                                            ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                            : '-';
                                        $startDate = \Carbon\Carbon::parse(now())->format('d-m-Y');
                                    @endphp
                                    <div class="dropdown-itemm active">
                                        <div class="d-flex flex-column">
                                            {{-- <span class="time">{{ $startDate }}</span>
                                        <span class="time">{{ $startTime }}</span> --}}
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Announcement & Notice</a>
                                            </div>
                                            <div class="sub-heading">{{ $value->task_title }} on {{ $startDate }}</div>
                                            <div class="link">
                                                <a href="" class="startDate">{{ $startDate }}</a>

                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                            @if (isset($data['upcoming']['hrmsPunchInOut']) && !empty($data['upcoming']['hrmsPunchInOut']))
                                @foreach ($data['upcoming']['hrmsPunchInOut'] as $key => $value)
                                    @php
                                        $startTime = \Carbon\Carbon::parse($value->punch_in)->format('H:i A');
                                        $endTime = \Carbon\Carbon::parse($value->punch_out)->format('H:i A');
                                        $startDate = \Carbon\Carbon::parse(now())->format('d-m-Y');
                                    @endphp
                                   
                                      <div class="dropdown-itemm">
                                        <div class="d-flex flex-column">
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                            {{-- <span class="time">{{ $startDate }}</span> --}}
                                            {{-- <span class="time">{{ $startTime }}</span> --}}
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Punch In/Out</a>
                                            </div>
                                            <div class="sub-heading">Your {{ $value->user_name }} time Punch In
                                                    {{ $startTime }} and Punch Out {{ $endTime }} </div>
                                           <div class="link">
                                                <a href="" class="startDate">{{ $startDate }}</a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if (isset($data['upcoming']['taskAssigned']) && !empty($data['upcoming']['taskAssigned']))
                                @foreach ($data['upcoming']['taskAssigned'] as $key => $value)
                                    @php
                                        $startTime = $value->created_at
                                            ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                            : '-';
                                        $startDate = \Carbon\Carbon::parse($value->task_date)->format('d-m-Y');
                                    @endphp
                                    <div class="dropdown-itemm">
                                        <div class="d-flex flex-column">
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                            {{-- <span class="time">{{ $startDate }}</span> --}}
                                            {{-- <span class="time">{{ $startTime }}</span> --}}
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Task Assigned</a>
                                            </div>
                                            <div class="sub-heading">{{ $value->task_title }} </div>
                                            <div class="link">
                                                <a href="" class="startDate">{{ $startDate }}</a>
                                                <a class="assignedTo" href="">Assigned to
                                                    {{ $value->task_user_name }}</a>
                                                <a class="viewTask" href="{{ route('task.index') }}" target="_blank">View
                                                    Now</a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                        </div>
                        <!-- upcoming body end  -->
                    </div>
                </div>
            </div>
            <!-- upcoming end  -->
            <!-- ============================================================================================================================== -->
            <!-- today start  -->
            <div class="dropdown-container">
                <div style="width: 100%">
                    <div id="dropdown-2" class="dropdownn">
                        <!-- today head  -->
                        <div type="button" id="dropdownMenuButton" style="padding: 10px 30px" class="active">
                            <h5 class="d-inline mr-2 sub-p"><b>Today</b></h5>
                            <p class="d-inline" style="color: #7d7d7d; font-weight: 500">
                                {{ $data['todaytitle'] }}
                            </p>
                            <img class="arrow-down"
                                src="{{ asset('/activity_stream_asset/arrow-down-sign-to-navigate.png') }}"
                                height="12" />
                        </div>
                        <!-- today body  -->
                        <div class="dropdown-item-list d-block">

                            @if (isset($data['today']['eventCalender']) && !empty($data['today']['eventCalender']))
                                @foreach ($data['today']['eventCalender'] as $key => $value)
                                    @php
                                        $startTime = $value->created_at
                                            ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                            : '-';
                                        $startDate = $value->school_date
                                            ? \Carbon\Carbon::parse($value->school_date)->format('d-m-Y')
                                            : '-';
                                    @endphp
                                    <div class="dropdown-itemm">
                                        <div class="d-flex flex-column">
                                            {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Events & Calender</a>
                                            </div>
                                            <div class="sub-heading">{{ $value->task_title }} on {{ $startDate }}
                                            </div>

                                        </div>
                                    </div>
                                @endforeach
                            @endif
                            @if (isset($data['today']['announcementNotice']) && !empty($data['today']['announcementNotice']))
                                @foreach ($data['today']['announcementNotice'] as $key => $value)
                                    @php
                                        $startTime = $value->created_at
                                            ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                            : '-';
                                    @endphp
                                    <div class="dropdown-itemm">
                                        <div class="d-flex flex-column">
                                            {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Announcement & Notice</a>
                                            </div>
                                            <div class="sub-heading">{{ $value->task_title }} on {{ $startDate }}
                                            </div>

                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if (isset($data['today']['hrmsPunchInOut']) && !empty($data['today']['hrmsPunchInOut']))
                                @foreach ($data['today']['hrmsPunchInOut'] as $key => $value)
                                    @php
                                        $startTime = \Carbon\Carbon::parse($value->punchin_time)->format('H:i A');
                                        $endTime = \Carbon\Carbon::parse($value->punchout_time)->format('H:i A');
                                        $startDate = \Carbon\Carbon::parse($value->day)->format('d-m-Y');
                                    @endphp
                                    <div class="dropdown-itemm">
                                        <div class="d-flex flex-column">
                                            {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Punch In/Out</a>
                                            </div>
                                            <div class="sub-heading">Your {{ $value->user_name }} time Punch In
                                                {{ $startTime }} and Punch Out {{ $endTime }}
                                            </div>

                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if (isset($data['today']['taskAssigned']) && !empty($data['today']['taskAssigned']))
                                @foreach ($data['today']['taskAssigned'] as $key => $value)
                                    @php
                                        $startTime = $value->created_at
                                            ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                            : '-';
                                        $startDate = \Carbon\Carbon::parse($value->task_date)->format('d-m-Y');
                                    @endphp
                                    <div class="dropdown-itemm" data-toggle="modal" data-target="#exampleModal">
                                        <div class="d-flex flex-column">
                                            {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                            <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                        </div>
                                        <div class="disc-and-line">
                                            <div class="disc"></div>
                                            <div class="line"></div>
                                        </div>
                                        <div>
                                            <div class="heading">
                                                <a href="">Task Assigned</a>
                                            </div>
                                            <div class="sub-heading">{{ $value->task_title }} </div>
                                            <div class="link">
                                                <a class="assignedTo" href="">Assigned to
                                                    {{ $value->task_user_name }}</a>
                                                <a class="viewTask" href="{{ route('task.index') }}"
                                                    target="_blank">View Now</a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                        </div>
                        <!-- check list  -->
                        {{-- <div class="col-lg-12 col-sm-12 col-xs-12 col-md-12 pb-2 text-right">
                            <a class="btn btn-info add-new" data-toggle="modal" data-target="#exampleModal">Today's Check
                                List</a>
                        </div> --}}
                        <!-- check list ends  -->
                    </div>

                </div>
            </div>
        </div>
        <!-- today end   -->
        <!-- ============================================================================================================================== -->
        <!-- recent start  -->
        <div class="dropdown-container">
            <div style="width: 100%">
                <div id="dropdown-3" class="dropdownn">
                    <!-- RECENT HEAD  -->
                    <div type="button" id="dropdownMenuButton" style="padding: 10px 30px">
                        <h5 class="d-inline mr-2 sub-p">Recent</h5>
                        <img class="arrow-down"
                            src="{{ asset('/activity_stream_asset/arrow-down-sign-to-navigate.png') }}" height="12" />
                    </div>
                    <!-- recent body -->
                    <div class="dropdown-item-list d-none">


                        @if (isset($data['recent']['eventCalender']) && !empty($data['recent']['eventCalender']))
                            @foreach ($data['recent']['eventCalender'] as $key => $value)
                                @php
                                    $startTime = $value->created_at
                                        ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                        : '-';
                                    $startDate = $value->school_date
                                        ? \Carbon\Carbon::parse($value->school_date)->format('d-m-Y')
                                        : '-';
                                @endphp
                                <div class="dropdown-itemm">
                                    <div class="d-flex flex-column">
                                        {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                        <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                    </div>
                                    <div class="disc-and-line">
                                        <div class="disc"></div>
                                        <div class="line"></div>
                                    </div>
                                    <div>
                                        <div class="heading">
                                            <a href="">Events & Calender</a>
                                        </div>
                                        <div class="sub-heading">{{ $value->task_title }} on {{ $startDate }}
                                        </div>
                                        <div class="link">
                                            <a href="" class="startDate">{{ $startDate }}</a>

                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                        @if (isset($data['recent']['announcementNotice']) && !empty($data['recent']['announcementNotice']))
                            @foreach ($data['recent']['announcementNotice'] as $key => $value)
                                @php
                                    $startTime = $value->created_at
                                        ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                        : '-';
                                    $startDate = \Carbon\Carbon::parse(now())->format('d-m-Y');
                                @endphp
                                <div class="dropdown-itemm active">
                                    <div class="d-flex flex-column">
                                        {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                        <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                    </div>
                                    <div class="disc-and-line">
                                        <div class="disc"></div>
                                        <div class="line"></div>
                                    </div>
                                    <div>
                                        <<div class="heading">
                                            <a href="">Announcement & Notice</a>
                                    </div>
                                    <div class="sub-heading">{{ $value->task_title }} on {{ $startDate }}
                                    </div>
                                    <div class="link">
                                        <a href="" class="startDate">{{ $startDate }}</a>

                                    </div>
                                </div>
                    </div>
                    @endforeach
                    @endif

                    @if (isset($data['recent']['hrmsPunchInOut']) && !empty($data['recent']['hrmsPunchInOut']))
                        @foreach ($data['recent']['hrmsPunchInOut'] as $key => $value)
                            @php
                                $startTime = \Carbon\Carbon::parse($value->punchin_time)->format('H:i A');
                                $endTime = \Carbon\Carbon::parse($value->punchout_time)->format('H:i A');
                                $startDate = \Carbon\Carbon::parse($value->day)->format('d-m-Y');
                            @endphp
                            <div class="dropdown-itemm">
                                <div class="d-flex flex-column">
                                    {{-- <span class="time">{{ $startDate }}</span>
                                            <span class="time">{{ $startTime }}</span> --}}
                                    <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                </div>
                                <div class="disc-and-line">
                                    <div class="disc"></div>
                                    <div class="line"></div>
                                </div>
                                <div>
                                    {{-- <span class="heading"></span> --}}
                                    <div class="heading">
                                        <a href="">Punch In/Out</a>
                                    </div>
                                    <div class="sub-heading">Your {{ $value->user_name }} time Punch In
                                        {{ $startTime }} and Punch Out {{ $endTime }}
                                    </div>
                                    <div class="link">
                                        <a href="" class="startDate">{{ $startDate }}</a>

                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    @if (isset($data['recent']['taskAssigned']) && !empty($data['recent']['taskAssigned']))
                        @foreach ($data['recent']['taskAssigned'] as $key => $value)
                            @php
                                $startTime =
                                    isset($value->created_at) && $value->created_at
                                        ? \Carbon\Carbon::parse($value->created_at)->format('H:i A')
                                        : '-';

                                $startDate =
                                    isset($value->task_date) && $value->task_date
                                        ? \Carbon\Carbon::parse($value->task_date)->format('d-m-Y')
                                        : '-';
                            @endphp
                            <div class="dropdown-itemm">
                                <div class="d-flex flex-column">
                                    {{-- <span class="time">{{ $startDate }}</span>
                                        <span class="time">{{ $startTime }}</span> --}}
                                    <span class="time"><span class="mdi mdi-calendar-check"></span></span>
                                </div>
                                <div class="disc-and-line">
                                    <div class="disc"></div>
                                    <div class="line"></div>
                                </div>
                                <div>
                                    <div class="heading">
                                        <a href="">Task Assigned</a>
                                    </div>
                                    <div class="sub-heading">{{ $value->task_title }} </div>
                                    <div class="link">
                                        <a href="" class="startDate">{{ $startDate }}</a>
                                        <a class="assignedTo" href="">Assigned to
                                            {{ $value->task_user_name }}</a>
                                        <a class="viewTask" href="{{ route('task.index') }}" target="_blank">View
                                            Now</a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endif

                </div>
                <!-- recent body end-->
            </div>
        </div>
    </div>
    <!-- recent end  -->
    <!-- container div end  -->
    </div>
    </div>

    <!--check list Modal -->
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
                    <table class="table table-bordered" style="filter:unset !important">
                        <thead>
                            <tr>
                                <th>Sr No</th>
                                <th>Task</th>
                                <th>Status</th>
                                <th>Activity Type</th>
                                <th class="text-left">Reply</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['checkList'] as $k => $value)
                                <tr>
                                    <td>{{ $k + 1 }}</td>
                                    <td>{{ $value->task_title }}</td>
                                    <td>{{ $value->status }}</td>
                                    <td>
                                        @php
                                            $activity_type = 'Observe';
                                            if ($value->task_allocated_to == $value->user_id) {
                                                $activity_type = 'To Do';
                                            }
                                        @endphp
                                        {{ $activity_type }}
                                    </td>
                                    <td>{{ $value->reply }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- check list modal ends  -->
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"
        integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous">
    </script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.9/dist/umd/popper.min.js"
        integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous">
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/js/bootstrap.min.js"
        integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous">
    </script>
    <script src="{{ asset('activity_stream_asset/script.js') }}"></script>
@endsection

@extends('layout')
@section('content')

                <div class="content-main flex-fill">
                    
                    @php
                    $user_profile = Session::get('user_profile_name');
                    $show_block = 'NO';
                    if(strtoupper($user_profile) == 'LMS TEACHER')
                    {
                        $show_block = 'YES';
                    }
                    @endphp
               
                    <div class="tab-title d-table mb-4 mx-auto">
                        <ul class="nav nav-tabs border-0" id="titleTab" role="tablist">
                            @php $i = 1; @endphp
                            @if(isset($data['content_category']))
                            @foreach($data['content_category'] as $ckey => $cval)
                            @php
                                $active_tab = "";
                                $tab_name = str_replace(' ', '',$cval['category_name']);
                                if(isset($_REQUEST['activeMenu']) && $_REQUEST['activeMenu']==$tab_name){
                                    $active_tab = "active";
                                }
                                else if(!isset($_REQUEST['activeMenu']) && $i == 1)
                                {
                                    $active_tab = "active";
                                }
                                $i++;
                            @endphp
                                <li class="nav-item">
                                    <a class="nav-link {{$active_tab}}" id="{{$tab_name}}-tab" data-toggle="tab" href="#{{$tab_name}}" role="tab" aria-controls="home" aria-selected="true">{{$cval['category_name']}}</a>
                                </li>
                            @endforeach
                            @endif

                            @if($show_block == 'YES')
                            <!-- <li class="nav-item">
                                <a class="nav-link" id="newcourse-tab" data-toggle="tab" href="#newcourse" role="tab" aria-controls="profile" aria-selected="false">Add New Course</a>
                            </li> -->
                            @endif

                        </ul>
                    </div>

                    <div>
                        {{-- {{ route('course_search') }} --}}
                        <form action="" method="GET">
                            {{ method_field("POST") }}
                            @csrf
                            @if(in_array(session()->get('sub_institute_id'),[195]))
                            <input type="hidden" name="perm" value="1">
                            @endif
                            <div class="row form-group align-items-center justify-content-center">

                                @if(strtoupper($user_profile) == 'LMS TEACHER' || strtoupper($user_profile) == 'ADMIN')

                                    @if(isset($data['grade']) && $data['grade'] != "" && isset($data['standard']) && $data['standard'] != "")
                                        {{ App\Helpers\SearchChain('3','','grade,std',$data['grade'],$data['standard']) }}
                                    @else
                                        {{ App\Helpers\SearchChain('3','','grade,std') }}
                                    @endif
                                    <div class="col-md-3 col-lg-2 text-left">
                                        <input type="submit" name="submit" value="Search" class="btn btn-info">
                                    </div>
                                @endif
                            </div>
                        </form>
                    </div>

                    <div class="container-fluid mb-5 tab-content">
                        <div class="tab-content" id="myTabContent">
                            @php $j = 1; @endphp
                            @if(isset($data['content_category']))
                            @foreach($data['content_category'] as $ckey => $cval)
                                    @php
                                        $active_body_tab = "";
                                        $tab_name = str_replace(' ', '',$cval['category_name']);
                                        if(isset($_REQUEST['activeMenu']) && $_REQUEST['activeMenu']==$tab_name){
                                            $active_tab = "active";
                                        }
                                        else if(!isset($_REQUEST['activeMenu']) && $j == 1)
                                        {
                                            $active_body_tab = "show active";
                                        }
                                        $j++;
                                        $style="";
                                    @endphp
                                    <div class="tab-pane fade {{$active_body_tab}}" id="{{$tab_name}}" role="tabpanel"
                                         aria-labelledby="{{$tab_name}}-tab">
                                        <div class="row" class="content-row">
                                            @if(isset($data['lms_subject'][$cval['category_name']]))
                                                @foreach($data['lms_subject'][$cval['category_name']] as $key => $val)

                                                    @php
                                                        $sub_institute_id = Session::get('sub_institute_id');
                                                        $syear = Session::get('syear');
                                                        $standard_id = $val['standard_id'];
                                                        $subject_id = $val['subject_id'];

                                                        // $booklist_data = DB::select("SELECT * FROM book_list
                                                                                // WHERE standard_id = $standard_id AND subject_id = $subject_id AND chapter_id = 0 AND topic_id = 0 AND sub_institute_id = $sub_institute_id AND syear = $syear ");
                                                        $booklist_data =[];
                                            if($val['sub_institute_id']!=$data['sub_institute_id']){
                                                $style="style=padding:57px !important";
                                            }
                                            if($val['sub_institute_id']==$data['sub_institute_id']){
                                                $style="";
                                            }                                                        
                                    @endphp

                                    <div class="col-12 col-sm-6 col-md-6 col-lg-3 mb-3 mb-md-4">
                                        <div class="card course-box" {{$style}}>
                                            <div class="course-img">
                                            @if($val['sub_institute_id']==$data['sub_institute_id'])
                                            <div class="d-flex align-items-start px-3 @if(!empty($booklist_data)) justify-content-between @else justify-content-end @endif">
                                                @if(!empty($booklist_data))
                                                    <div class="single-item position-relative">
                                                        <i class="mdi mdi-dots-vertical-circle-outline"></i>
                                                        <ul class="sub-menu">
                                                            @foreach($booklist_data as $k => $book_data)
                                                            @php
                                                                $file_name = '';
                                                                if($book_data['file_name'] != '')
                                                                {
                                                                    $file_name = '/storage/book_list/'.$book_data['file_name'];
                                                                }else{
                                                                    $file_name = $book_data['link'];
                                                                }
                                                            @endphp
                                                                <li>
                                                                    <a target="_blank" href="{{$file_name}}"
                                                                       class="text-dark">{{$book_data['title']}}</a>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                                @php
                                                if(isset($_REQUEST['preload_lms'])){
                                                    $pre_load = "preload_lms=preload_lms";
                                                }
                                                @endphp
                                                <a target="_blank"
                                                   href="{{ route('subjectwise_graph.show',['subjectwise_graph'=>$val['subject_id'],'standard_id'=>$val['standard_id'],'action'=>'subjectwise']) }}"
                                                   class="d-block">
                                                    <img src="../../../admin_dep/images/graph_icon.png" height="25"
                                                         class="object-cover h-25">
                                                </a>
                                            </div>
                                            @endif                                            
                                                <a href="{{ route('chapter_master.index',['standard_id'=>$val['standard_id'],'subject_id'=>$val['subject_id'],'perm'=>$val['sub_institute_id']]) }}">
                                                    <img src="../../../storage{{$val['display_image']}}" alt=""
                                                         width="25%">
                                                </a>
                                            </div>

                                            <div class="course-name"><a
                                                    href="{{ route('chapter_master.index',['standard_id'=>$val['standard_id'],'subject_id'=>$val['subject_id'],$pre_load ?? '','perm'=>$val['sub_institute_id']]) }}">{{$val['subject_name']}}</a>
                                                <div>{{$val['standard_name']}}</div>
                                            </div><!-- [ {{$val['standard_name']}} ]-->
                                            @if($val['sub_institute_id']==$data['sub_institute_id'])
                                            <div class="course-bottom">
                                                @if($show_block == 'YES')
                                                <div class="single-item">
                                                    <a href="{{ route('lms_lessonplan.index',['standard_id'=>$val['standard_id'],'subject_id'=>$val['subject_id'],'title'=>'']) }}">
                                                        <i class="mdi mdi-book-outline"></i> Lesson Planning
                                                    </a>
                                                </div>
                                                @endif
                                                <div class="single-item">
                                                    <a href="#">
                                                        <i class="mdi mdi-pencil-box-multiple-outline"></i> Assessment
                                                    </a>
                                                </div>

                                                @php
                                                    $chapter_arr = array();
                                                    if($val['chapter_list'] != "")
                                                    {
                                                        $chapter_arr = explode("#",$val['chapter_list']);
                                                    }
                                                @endphp
                                                @if(isset($chapter_arr) && count($chapter_arr) > 0)
                                                    <div class="single-item">
                                                        <i class="mdi mdi-dots-vertical-circle-outline"></i>

                                                        <ul class="sub-menu">
                                                            @foreach($chapter_arr as $k => $v)
                                                            @php
                                                                $topic_arr = explode("/",$v);
                                                                if(isset($topic_arr[1])){
                                                                $topic_id = $topic_arr[1];
                                                                }

                                                                @endphp
                                                                <li>
                                                                    <a href="{{ route('topic_master.index',['id'=>$topic_id ?? '',$pre_load ?? '']) }}">{{$topic_arr[0]}}</a>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            </div>
                                            @endif
                                            
                                        </div>
                                    </div>
                                                @endforeach
                                            @else
                                                <div class="card col-md-12">
                                                    <div class="form-group mt-3">
                                                        <center>No Records Found.</center>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                            @endforeach
                        @endif

                        <!--New courses-->
                        <!--  <div class="tab-pane fade " id="newcourse" role="tabpanel" aria-labelledby="newcourse-tab">
                                <div class="row">
                                @if(isset($data['lms_subject']['NEW']))
                            @foreach($data['lms_subject']['NEW'] as $key => $val)
                                <div class="col-md-3 mb-3 mb-md-4">
                                    <div class="card course-box">
                                        <div class="course-img" style="height:33%;">
                                            <a href="{{ route('chapter_master.index',['standard_id'=>$val['standard_id'],'subject_id'=>$val['subject_id']]) }}">
                                                    <img src="../../../storage{{$val['display_image']}}" alt="" width="25%">
                                                </a>
                                            </div>
                                            <div class="course-name"><a href="{{ route('chapter_master.index',['standard_id'=>$val['standard_id'],'subject_id'=>$val['subject_id']]) }}">{{$val['subject_name']}}</a><div>{{$val['standard_name']}}</div></div>
                                        </div>
                                    </div>
                                    @endforeach
                        @endif
                            </div>
                        </div> -->
                            <!-- <div class="tab-pane fade" id="skill" role="tabpanel" aria-labelledby="skill-tab">Skill</div>
                            <div class="tab-pane fade" id="vorational" role="tabpanel" aria-labelledby="vorational-tab">Vorational</div> -->
                        </div>

                    </div>

                </div>
@endsection

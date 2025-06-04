@extends('layout')
@section('content')
<div id="page-wrapper">
   <div class="container-fluid">

      <div class="row bg-title">
         <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
            <h4 class="page-title">Add Masters</h4>
         </div>
      </div>

      <div class="card">
         @if ($sessionData = Session::get('success'))
         <div class="alert alert-success alert-block alert">
            <button type="button" class="close" data-dismiss="alert">×</button>
            <strong>{{ $sessionData }}</strong>
         </div>
         @endif
         @if ($sessionData = Session::get('failed'))
         <div class="alert alert-danger alert-block alert">
            <button type="button" class="close" data-dismiss="alert">×</button>
            <strong>{{ $sessionData }}</strong>
         </div>
         @endif
         <div class="row">
            <div class="col-lg-12 col-sm-12 col-xs-12">
               <form name="master" action="{{url('/school_setup/insert_data')}}" method="post" enctype="multipart/form-data">
                  @csrf
                  <center>
                     <div class="col-md-3 form-group">
                        <label>Select Master <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                        <select name="Slsection" id="Slsection" class="form-control">
                           <option value="">select section</option>
                           <option value="1">Academy</option>
                           <option value="2">Standard</option>
                           <option value="4">Subject</option>
                        </select>
                     </div>
                  </center>
                  <!-- pop ups for acadmic,standard,division,subject -->
                  <div class="row" id="hiddenRow" style="border:1px solid #Ddd;margin:10px;border-radius:10px;">
                     <!-- academic section 1 -->
                     <div class="col-md-12 form-group sectionacd d--none" style="display: none;">
                        <div class="row">
                           <div class="col-md-3">
                              <label>Title <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" name="ac_title" id="ac_title" class="form-control" placeholder="Enter Title">
                           </div>
                           <div class="col-md-3">
                              <label>Short Name <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" name="ac_short_name" id="ac_short_name" class="form-control" placeholder="Enter Short Name">
                           </div>
                           <div class="col-md-3">
                              <label>Sort Order <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="number" name="ac_sort_order" id="ac_sort_order" class="form-control" placeholder="Enter Sort Order">
                           </div>
                           <div class="col-md-3">
                              <label>Shift</label>
                              <input type="text" name="ac_shift" id="ac_shift" class="form-control" placeholder="Enter Shift">
                           </div>
                           <div class="col-md-3">
                              <label>Medium</label>
                              <input type="text" name="ac_medium" id="ac_medium" class="form-control" placeholder="Enter Medium">
                           </div>
                        </div>
                     </div>
                     <!-- academic section end -->
                     <!-- standard 2 -->
                     <div class="col-md-12 form-group sectionstd d--none" style="display: none;">
                        <div class="row">
                           <div class="col-md-3">
                              <label>Select Grade <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <select name="st_grade" id="st_grade" class="form-control">
                                 <option>select</option>
                                 @foreach($data['grade'] as $grade1)
                                 <option value="{{$grade1->id}}">{{$grade1->short_name}}</option>
                                 @endforeach
                              </select>
                           </div>
                           <div class="col-md-3">
                              <label>Name <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" name="st_name" id="st_name" class="form-control" placeholder="Enter Standard Name">
                           </div>
                           <div class="col-md-3">
                              <label>Short Name <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" name="st_short_name" id="st_short_name" class="form-control" placeholder="Enter Short Name">
                           </div>
                           <div class="col-md-3">
                              <label>Sort Order <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="number" name="st_sort_order" name="st_sort_order" class="form-control" placeholder="Enter Sort Order">
                           </div>
                           <div class="col-md-3">
                              <label>Medium</label>
                              <input type="text" name="st_medium" class="form-control" placeholder="Enter Medium">
                           </div>
                           <div class="col-md-3">
                              <label>Course Duration</label>
                              <input type="text" name="st_course_duration" class="form-control" placeholder="Enter Course Duration">
                           </div>
                           <div class="col-md-3">
                              <label>Select Next Grade</label>
                              <select name="st_next_grade" id="st_next_grade" class="form-control">
                                 <option value="">select</option>
                                 @foreach($data['grade'] as $grade1)
                                 <option value="{{$grade1->id}}">{{$grade1->short_name}}</option>
                                 @endforeach
                              </select>
                           </div>
                           <div class="col-md-3">
                              <label>Select Next Standard</label>
                              <select name="st_next_standard" id="st_next_standard" class="form-control"></select>
                           </div>
                        </div>
                     </div>
                     <!-- standar end -->
                     <!-- division -->
                     <div class="col-md-12 form-group sectiondvs d--none" style="display: none;">
                        <div class="row">
                           <div class="col-md-3 form-group">
                              <label>Enter Division</label>
                              <!-- <input type="text" name="institute" value="{{Session::get('sub_institute_id')}}"> -->
                              <input type="text" name="div_name" class="form-control" placeholder="Enter Division">
                           </div>
                        </div>
                     </div>
                     <!-- end division -->
                     <!-- subject  -->
                     <div class="col-md-12 form-group sectionsub d--none" style="display: none;">
                        <div class="row">
                           <div class="col-md-3 form-group">
                              <label>Subject Name <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" id='subject_name' name="subject_name" id="subject_name" class="form-control" placeholder="Enter Subject Name">
                           </div>
                           <div class="col-md-3 form-group">
                              <label>Subject Code <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" id='subject_code' name="subject_code" id="subject_code" class="form-control" placeholder="Enter Subject Code">
                           </div>
                           <div class="col-md-3 form-group">
                              <label>Short Name <span class="mdi mdi-asterisk" style="color:red;font-size:0.6rem;"></span></label>
                              <input type="text" id='short_name' name="short_name" id="short_name" class="form-control" placeholder="Enter Short Name">
                           </div>
                           <div class="col-md-2 form-group checkbox checkbox-info checkbox-circle mt-4">      
                              <input type="checkbox" id="subject_type" name="subject_type" value="Major">
                              <label for="subject_type">Major</label> 
                           </div>
                        </div>
                     </div>
                     <!-- subject end -->
                  </div>
                  <!-- pop up divs end -->
                  <div class="col-md-12 form-group mt-2">
                     <center>
                        <input type="submit" name="submit" value="Save" class="btn btn-success" >
                     </center>
                  </div>
               </form>
            </div>
         </div>
      </div>
   </div>


<div class="card m-3">
   <div class="row">
      <div class="col-md-12">
    <center>
        <ul class="nav nav-tabs" id="myTab" role="tablist">
            <li class="nav-item" role="presentation" onclick="datatableCall('academicSectionTable')">
                <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#1-tab-pane" type="button" role="tab" aria-controls="1-tab-pane" aria-selected="true">Academic Sections</button>
            </li>
            <li class="nav-item" role="presentation" onclick="datatableCall('standardTable')">
                <button class="nav-link" id="home-tab" data-bs-toggle="tab" data-bs-target="#2-tab-pane" type="button" role="tab" aria-controls="2-tab-pane" aria-selected="true">Standards</button>
            </li>
            <li class="nav-item" role="presentation" onclick="datatableCall('subjectTable')">
                <button class="nav-link" id="home-tab" data-bs-toggle="tab" data-bs-target="#3-tab-pane" type="button" role="tab" aria-controls="3-tab-pane" aria-selected="true">Subjects</button>
            </li>
        </ul>
    </center>
    </div>
    <div class="tab-content" id="myTabContent">
        {{-- tab 1 --}}
        <div class="tab-pane fade show active" id="1-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
            <div class="table-responsive">
               <table class="table table-striped" id="academicSectionTable">
                     <thead>
                        <tr>
                              <th>sr no</th>
                              <th>Title</th>
                              <th>Short Name</th>
                              <th>Sort Order</th>
                        </tr>
                     </thead>
                     <tbody>
                        @foreach ($data['grade'] as $k=>$val)
                              <tr>
                                 <td>{{$k+1}}</td>
                                 <td>{{$val['title']}}</td>
                                 <td>{{$val['short_name']}}</td>
                                 <td>{{$val['sort_order']}}</td>
                              </tr> 
                        @endforeach
                     </tbody>
                  </table>
            </div>
        </div>
        {{-- tab 2 --}}
        <div class="tab-pane fade show" id="2-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
            <table class="table  table-striped" id="standardTable">
                <thead>
                    <tr>
                        <th>sr no</th>
                        <th>Title</th>
                        <th>Short Name</th>
                        <th>Sort Order</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['standard'] as $k=>$val)
                        <tr>
                            <td>{{$k+1}}</td>
                            <td>{{$val['name']}}</td>
                            <td>{{$val['short_name']}}</td>
                            <td>{{$val['sort_order']}}</td>
                        </tr> 
                    @endforeach
                </tbody>
            </table>
        </div>
        {{-- tab 3 --}}

        <div class="tab-pane fade show" id="3-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
             <table class="table  table-striped" id="subjectTable">
                <thead>
                    <tr>
                        <th>sr no</th>
                        <th>Subject Name</th>
                        <th>Subject Code</th>
                        <th>Short Name</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['subject_data'] as $k=>$val)
                        <tr>
                            <td>{{$k+1}}</td>
                            <td>{{$val['subject_name']}}</td>
                            <td>{{$val['subject_code']}}</td>
                            <td>{{$val['short_name']}}</td>
                        </tr> 
                    @endforeach
                </tbody>
            </table>

        </div>
    
    </div>
   </div>
</div>

</div>
</div>
<script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.19/js/dataTables.bootstrap.min.js"></script>
<script>
   $(document).ready(function(){
      datatableCall('academicSectionTable');
   })
   $('#hiddenRow').hide();
   $('body').on('change', '#Slsection', function(){
       $('#hiddenRow').show();
       $('#ac_title, #ac_short_name, #ac_sort_order').prop('required', false);
       $('#st_grade,#st_name,#st_short_name,#st_sort_order').prop('required', false);
       $('#subject_name,#subject_code,#short_name').prop('required', false);
      
       var sec = $(this).val();
       // make fields requred 
       if(sec ==1){ $('#ac_title, #ac_short_name, #ac_sort_order').prop('required', true); }
       if(sec ==2){ $('#st_grade,#st_name,#st_short_name,#st_sort_order').prop('required', true); }
       if(sec ==4){ $('#subject_name,#subject_code,#short_name').prop('required', true); }
   
       $.ajax({
           url:'{{route("collectsct")}}',
           data:{"_token":"{{csrf_token()}}", "sectionId": $(this).val()},
           type:'post',
           datatype:'json',
           success:function(data){
               $('.sectionacd').hide(); $('.sectionstd').hide(); $('.sectiondvs').hide();$('.sectionsub').hide();
               if(sec ==1){ $('.sectionacd').show(); $('#Academy').html(data); }
               if(sec ==2){ $('.sectionstd').show(); $('#Standard').html(data); }
               if(sec ==3){ $('.sectiondvs').show(); $('#Division').html(data); }
               if(sec ==4){ $('.sectionsub').show(); $('#Subject').html(data); }
           }
       });
   });
   
   $(document).on('change','#st_next_grade' ,function(){
   var val = $('#st_next_grade option:selected').val();
   // console.log(val);
   $.ajax({
           url:'{{route("collectsct")}}',
           data:{"_token":"{{csrf_token()}}", "sectionId":5, "grade": val},
           type:'post',
           datatype:'json',
           success:function(data){
            $('#st_next_standard').html(data);
           }
       });
   });

   function datatableCall(tableId) {
    // Check if DataTable already exists and destroy it
    if ($.fn.DataTable.isDataTable('#' + tableId)) {
        $('#' + tableId).DataTable().destroy();
        // Remove cloned header row if it exists
        $('#' + tableId + ' thead tr:eq(1)').remove();
    }

    var table = $('#' + tableId).DataTable({
        select: true,
        lengthMenu: [
            [100, 500, 1000, -1],
            ['100', '500', '1000', 'Show All']
        ],
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'pdfHtml5',
                title: 'Data Report',
                orientation: 'landscape',
                pageSize: 'A0',
                exportOptions: {
                    columns: ':visible'
                },
            },
            {extend: 'csv', text: ' CSV', title: 'Data Report'},
            {extend: 'excel', text: ' EXCEL', title: 'Data Report'},
            {extend: 'print', text: ' PRINT', title: 'Data Report'},
            'pageLength'
        ],
    });

    $('#' + tableId + ' thead tr').clone(true).appendTo('#' + tableId + ' thead');
    $('#' + tableId + ' thead tr:eq(1) th').each(function (i) {
        var title = $(this).text();
        $(this).html('<input type="text" placeholder="Search ' + title + '" />');

        $('input', this).on('keyup change', function () {
            if (table.column(i).search() !== this.value) {
                table
                    .column(i)
                    .search(this.value)
                    .draw();
            }
        });
    });
}

</script>
@endsection
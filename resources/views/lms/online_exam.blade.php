@extends('layout')
@section('content')
<link rel="stylesheet" href="../../../tooltip/enjoyhint/jquery.enjoyhint.css">
<style>
html {
  scroll-behavior: smooth !important;
}
 br{
    display:  block !important;
}
</style>

<!-- Content main Section -->
<div class="content-main flex-fill">
    <div class="container-fluid mb-5">
        <div class="course-grid-tab tab-pane fade show active" id="grid" role="tabpanel" aria-labelledby="grid-tab">
            <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                <li class="nav-item">
                  <a class="nav-link active" id="chat-tab" data-toggle="pill" href="#chat" role="tab" aria-controls="chat-tab" aria-selected="false">Online Question Paper: {{$data['questionpaper_data']['paper_name']}}</a>
                </li>
            </ul>
            <form id="online_exam" method="post" action="{{ route('online_exam.store') }}">
            {{ method_field('POST') }}
            @csrf

            <input type="hidden" name="hid_session_quiz" id="hid_session_quiz" value="{{ now() }}">
            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade show active" id="chat" role="tabpanel" aria-labelledby="chat-tab">
                    <div class="card border-0 rounded mb-5">
                        <div class="card-body">
                            <div class="d-md-flex align-items-center justify-content-between">
                                <div class="quiz-labels">
                                    <div class="h4">Quiz Navigation</div>
                                    <ul class="quiz-navigation">
                                         @php 
                                        $i = 1; 
                                        @endphp                           
                                        @foreach($data['question_arr'] as $quesid => $quesarr)
                                            <li class="nav-item">
                                                <a href="#question-{{$quesarr['id']}}-tab">{{$i++}}</a>
                                            </li>
                                        @endforeach    
                                    </ul>
                                </div>
                                <div class="quiz-time">
                                    <!-- <a href="#" class="btn btn-outline-primary mb-3">Start a new Preview</a>-->
                                    <div class="color-primary mb-2">Total Marks : {{$data['questionpaper_data']['total_marks']}}</div> 
                                    <div class="color-primary mb-2">(Total {{$data['questionpaper_data']['time_allowed']}} mins)</div> 
                                    <div class="text-secondary">Time Left: <p id="showtimer"></p></div>                                  
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="questionpaper_time" id="questionpaper_time" value="{{$data['questionpaper_data']['time_allowed']}}">
                    <input type="hidden" name="questionpaper_id" id="questionpaper_id" value="{{$data['questionpaper_data']['id']}}">
                    <div class="container-fluid mb-4">
                        <div class="quiz-box">
                            @php 
                            $i = 1;                           
                            @endphp                           
                            @foreach($data['question_arr'] as $quesid => $quesarr)
                            <div class="row mb-3" id="question-{{$quesarr['id']}}-tab">
                                <div class="col-2">
                                    <div class="quiz-box-count">
                                        <div class="count">{{$i++}}</div>
                                        <div class="quiz-con">
                                            <div class="text-secondary mb-2">Marked out of <b>{{$quesarr['points']}}</b></div>
                                            <!-- <div class="text-secondary mb-2">{{$quesarr['points']}}</div> -->
                                            @if(isset($quesarr['hint_text']))
                                            <div class="text-secondary"><i data-toggle="tooltip" title="{{$quesarr['hint_text']}}" class="mdi mdi-alert-circle"></i></div><!--mdi-flag-outline-->
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="col-10">
                                    <div class="card border-0 rounded">
                                        <div class="card-body">
                                            <!-- <a href="javascript:void(0)" class="float-right" data-container="body" data-toggle="popover" data-placement="left" data-content="Vivamus sagittis lacus vel augue laoreet rutrum faucibus." data-trigger="hover">
                                                <i class="mdi mdi-alert-circle-outline"></i>
                                            </a> -->
                                            <div class="quiz-title">{!!$quesarr['question_title']!!}</div>
                                            @if($quesarr['question_type_id'] == "2") <!--Narrative Question-->
                                            <div class="quiz-option">
                                                <textarea type="text" rows="4" placeholder="Enter Answer" class="form-control" name="answer_narrative[{{$quesarr['id']}}]"></textarea>
                                            </div>
                                            @elseif($quesarr['question_type_id'] == "1") <!--Multple Option Question-->
                                            <div class="quiz-option">
                                                 @if(isset($data['answer_arr'][$quesarr['id']]))                     
                                                    @foreach($data['answer_arr'][$quesarr['id']] as $ansid => $ansarr)
                                                    <ul>
                                                        @php
                                                        if($quesarr['multiple_answer'] == 1)
                                                        {
                                                            $btnclass = "square";
                                                            $type = "checkbox";
                                                            $name = "answer_multiple[".$quesarr['id']."][]";//[".$ansarr['id']."]";
                                                        }
                                                        else{                                                
                                                            $btnclass = "dot";
                                                            $type = "radio";
                                                            $name = "answer_single[".$quesarr['id']."]";
                                                        }
                                                        @endphp
                                                        <li>
                                                            <div>
                                                                <input type="{{$type}}" id="{{$name}}" name="{{$name}}" value="{{$ansarr['id']}}##{{$ansarr['correct_answer']}}">
                                                                {{$ansarr['answer']}}
                                                            </div>
                                                        </li>
                                                    </ul>
                                                    @endforeach
                                                @endif
                                            </div>

                                            @endif                         
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Submit</button>
            </form>
        </div>
    </div>

</div>


<script type="text/javascript">
// added on 06-01-2025 for back restrictions
$(document).ready(function() {
// alert('hello');
    function disableBack() {
        window.history.forward()
    }
    window.onload = disableBack();
    window.onpageshow = function(e) {
        if (e.persisted)
            disableBack();
    }
});
// added on 06-01-2025 for back restrictions

$(document).ready(function(){
    $('[data-toggle="tooltip"]').tooltip();   
});
</script>

<script src="//cdn.mathjax.org/mathjax/latest/MathJax.js"> 
 MathJax.Hub.Config({ 
   extensions: ["mml2jax.js"], 
   jax: ["input/MathML", "output/HTML-CSS"] 
 }); 
</script> 


<script>

// Get the minutes to add from input
const min_to_add = parseInt($("#questionpaper_time").val());

// Function to start the countdown timer
function startCountdown(minutes) {
    // Calculate end time (current time + minutes)
    const endTime = new Date();
    endTime.setMinutes(endTime.getMinutes() + minutes);
    
    // Update the timer every second
    const timerInterval = setInterval(function() {
        // Get current time
        const now = new Date();
        
        // Calculate remaining time
        const timeLeft = endTime - now;
        
        // If time is up
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            $("#showtimer").text("Time's up!");
            
            // Submit form and close window
            $("#online_exam").submit();
            window.close();
            return;
        }
        
        // Convert milliseconds to minutes and seconds
        const minutesLeft = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
        const secondsLeft = Math.floor((timeLeft % (1000 * 60)) / 1000);
        
        // Display the remaining time
        $("#showtimer").text(
            `${minutesLeft.toString().padStart(2, '0')}:${secondsLeft.toString().padStart(2, '0')}`
        );
    }, 1000); // Update every second
}

// Start the countdown when the page loads
$(document).ready(function() {
    if (!isNaN(min_to_add) && min_to_add > 0) {
        startCountdown(min_to_add);
    } else {
        $("#showtimer").text("Invalid time set");
    }
});


</script>

@endsection
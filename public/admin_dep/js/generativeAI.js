var i = 1;
var isFirstCharTyped = false;

$(document).on('click', '.note-editable', function(e) {
        checkVal();
        // checkAction(e);
        // 
        $('#content_div, #promptDiv').remove(); 
        var textWithoutTags = $('.note-editable').html();
        var summerText = textWithoutTags.replace(/<[^>]*>/g, '').trim();        
        // alert(summerText);
        if ((summerText === '' || summerText === null || summerText === undefined)) {
            checkAction(e);
        }
})

$(document).on('keydown', '.note-editable', function (e) {
    if (e.key === 'Enter') {
        checkVal();        
        checkAction(e);
    }
})

// function checkVal(){
//      // Get the input value
//      const inputValue = $('.textInput').val();
//      if ($('.textInput').length > 0) {
//          if(inputValue.split(' ')[0]!=='' && inputValue.split(' ')[0]!=='/'){
//             $('.mainDiv').remove();
//             var editableDiv = $('.note-editable')[0];
//             var newDiv = document.createElement('div');
//             newDiv.innerHTML = inputValue;
//             var range = document.getSelection().getRangeAt(0);
//             range.insertNode(newDiv);
//             $('.textInput').remove();
//         }
//     }
// }
function checkVal() {
    // Get the input value
    const inputValue = $('.textInput').val();

    if ($('.textInput').length > 0) {
        if (inputValue.trim() !== '') {
            $('.mainDiv, .textInput').remove();
            var existingContent = $('.summernote').summernote('code').trim();
            var newContent = existingContent + ' ' + inputValue;
            $('.summernote').summernote('code', newContent);
            var $editable = $('.summernote').next('.note-editor').find('.note-editable');
            var range = document.createRange();
            var sel = window.getSelection();
            var lastChild = $editable[0].lastChild;
            range.setStart(lastChild, lastChild.length);
            $('.note-editable').find('div,p').filter(function() {
                return $(this).text().trim() === '';
            }).remove();            
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }
}

function checkAction(e) {
    $('#ai_languages').hide();
    $('.lists_text ,.ai_languages').remove();
    $('.mainDiv').remove();
    $('#content_div').remove();    
    var editableDiv = $('.note-editable')[0];
    var range = document.getSelection().getRangeAt(0);
    var newDiv = document.createElement('div');
    newDiv.innerHTML = '<div class="mainDiv" id="mainDiv"><input class="textInput form-control border-0 shadow-lg p-3 mb-5 bg-white rounded" id="textInput" placeholder="Press ‘space’ or ‘/’ for AI" ></div>';
    range.insertNode(newDiv);
    $('.textInput').focus();
    isFirstCharTyped = false; // Reset for a new input
}

$(document).on('keydown', '.textInput', function (e) {
   
    const inputValue = $('.textInput').val();
    if (e.key === 'Enter' && e.key !== '/' && e.key !== ' ') {
        checkVal(); 
        return false; 
    }
    if (e.key === 'Backspace' && inputValue==='') {
        $('.note-editable').focus();
        $('.textInput').remove();        
        $('#content_div').remove();                    
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
       $('#list_focus').focus();
    }else if(e.key === ' ' && inputValue !== ''){
        $('.textInput').append(' ');
        $('.textInput').focus();
        $('#content_div').remove();                    
    }else if (e.key === '/' || e.key === ' ') {
            $('.textInput').focus();
            // $('#content_div').remove();            
            $('#ai_languages').hide();

            // Append the list and set focus on the first child
            $('.textInput').after(`<div class="content_div" id="content_div">
            <style>
               .lists_ul > .list-group-item{
               padding:6px 10px !important;
               }
               .list_div > p{
               margin-bottom:2px !important;
               }
            </style>
            <div class="d-flex" id="option_div">
               <div  id="lists_text" class="card lists_text" style="width:25%;height:250px;overflow-y:scroll;padding:10px !important">
                  <div class="list_div">
                     <p>Write with AI :</p>
                     <ul class="list-group lists_ul">
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('write something more about')" id="list_focus"><span><i class="fa fa-pencil" aria-hidden="true"></i></span> Continue Writing</li>
                     </ul>
                  </div>
                  <hr>
                  <div  class="list_div">
                     <p>Generate From Page :</p>
                     <ul class="list-group lists_ul">
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('make summary report')"><span>
                            <i class="fa fa-outdent" aria-hidden="true"></i></span> Summarize</li>
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('generate item lists')">
                            <span><i class="fa fa-list" aria-hidden="true"></i></span> Find action items</li>
                        <li class="list-group-item translate_block" contenteditable="false" onclick="aiChat('translate')">
                            <span><i class="fa fa-globe" aria-hidden="true"></i></span> Translate <span><i class="fa fa-angle-right" aria-hidden="true"></i></span></li>
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('explain given prompt')">
                            <span><i class="fa fa-question-circle" aria-hidden="true"></i></span> Explain this</li>
                     </ul>
                  </div>
                  <hr>
                  <div  class="list_div">
                     <p>Edit or Review Page :</p>
                     <ul class="list-group lists_ul">
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('fix grammar and spelling')">
                            <span><i class="fa fa-check" aria-hidden="true"></i></span> Fix spelling & grammar</li>
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('make sentences shorter')">
                            <span><i class="fa fa-minus-circle" aria-hidden="true"></i></span> Make shorter</li>
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('make sentences longer')">
                            <span><i class="fa fa-plus-circle" aria-hidden="true"></i></span> Make longer</li>
                        <li class="list-group-item" contenteditable="false" onclick="aiChat('generate list with checkbox inputs')">
                            <span><i class="fa fa-check-square" aria-hidden="true"></i></span> To Do List</li>
                     </ul>
                  </div>

               </div>
            </div>
         </div>`);
            $('.translate_block').mouseover(function () {
                $('.ai_languages').remove();
                $('.translate_block').after(`<div class="ai_languages ml-2" id="ai_languages">
                <ul class="list-group lists_ul">
                    <li class="list-group-item" contenteditable="false" onclick="aiChat('translate','English')">English</li>
                    <li class="list-group-item" contenteditable="false" onclick="aiChat('translate','Hindi')">Hindi</li>
                    <li class="list-group-item translate_block" contenteditable="false" onclick="aiChat('translate','Gujararti')">Gujararti</li>
                </ul>
            </div>`);
            })
        isFirstCharTyped = true;
        
    } else {
        $('.textInput').focus();
        $('.lists_text ,.ai_languages').remove();
    }
});

function aiChat(value,sub_val='') {
    // Remove elements
    $('.mainDiv').remove();
    $('#content_div, #option_div').remove();
    $('#promptDiv').remove();

    $('.summernote').summernote();
    // Get the value from Summernote when needed
    var textWithoutTags = $('.summernote').summernote('code');
    var summerText = textWithoutTags.replace(/<[^>]*>/g, '').trim();

    // Check if there's any text left
    if (summerText === '' || summerText === null || summerText === undefined) {
        $('.note-editable').append(`<div class="promptDiv" id="promptDiv"><style>.note-handle{display:none !important}</style><input type="hidden" id="vals" value="`+value+`"><input class="promptInput form-control border-0 shadow-lg p-3 mb-5 bg-white rounded" id="promptInput" placeholder="Please enter prompt to search......then hit  ‘Enter’ " ></div>`);
        isFirstCharTyped = false;
        
        $('.promptInput').focus();
    } else {
        chatReponse(value,textWithoutTags,sub_val);              
    }
    
}
$(document).on('keydown', '.promptInput', function (e) {
    if(e.key===' '){
        $('#promptInput').append(' ').focus();
    }
    var promptInput = $('#promptInput').val();
    var value = $('#vals').val();    
    $('.textInput').remove();              
    if (e.key === 'Enter' && promptInput !== ' ') {
        promptInputs(value);   
    }else if (e.key === 'Enter' && promptInput === ' '){
        $('#promptDiv').remove();  
        alert('Please add prompt');    
    }
});
function promptInputs(value,sub_val=''){
    var prompt = $('#promptInput').val();
    if (prompt === '' || prompt === null || prompt === undefined) {
        $('#promptDiv').remove();  
        alert('Please add prompt');      
    }else{
        chatReponse(value,prompt,sub_val);              
    }
}

function chatReponse(searchType,textareaInput,sub_val=''){
    // alert(textareaInput);
    $('#promptDiv').remove();  
    
    $.ajax({
        url: "/chat",  // Make sure to use single quotes for route() method
        data: { search: 'summernote', searchType: searchType, prompt: textareaInput,sub_val:sub_val },
        type: 'GET',
        success: function (result) {
            if(result.length > 0){
            $('.note-editable').empty();
            var editableDiv = $('.note-editable')[0];
             var newDiv = document.createElement('div');
              newDiv.innerHTML = result;
              var range = document.getSelection().getRangeAt(0);
             range.insertNode(newDiv);     
            }else{
                chatGptReponse(searchType,textareaInput,sub_val='');
            }
        },
        error: function (error) {
            // Handle errors here
            console.error('Error:', error);
        }
    });
}

function chatGptReponse(searchType,textareaInput,sub_val=''){
    // alert(textareaInput);
    $('#promptDiv').remove();  
    
    $.ajax({
        url: "/geminiAI",  // Make sure to use single quotes for route() method
        data: { search: 'summernote', searchType: searchType, prompt: textareaInput,sub_val:sub_val },
        type: 'GET',
        success: function (result) {
            $('.note-editable').empty();
            var editableDiv = $('.note-editable')[0];
             var newDiv = document.createElement('div');
              newDiv.innerHTML = result;
              var range = document.getSelection().getRangeAt(0);
             range.insertNode(newDiv);
        },
        error: function (error) {
            // Handle errors here
            console.error('Error:', error);
        }
    });
}


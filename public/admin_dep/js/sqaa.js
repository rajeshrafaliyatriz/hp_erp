
// add new row 
var data_new = 1;

function addNewRow1() {
    data_new++;

    var htmlcontent = '';
    htmlcontent += '<div class="clearfix"></div><div class="addButtonCheckbox1"  data-new="' + data_new +
        '"><div class="row align-items-center">';
    htmlcontent +=
        '<div class="col-md-3"><div class="form-group"><label for="topicAvailability">Document</label><textarea name="document[]" id="document' +
        data_new + '" rows="3" class="form-control" data-new="' + data_new + '" onchange="chat_gtp(this.value,' + data_new +
        ')"></textarea></div></div>';
    htmlcontent +=
        '<div class="col-md-2"><div class="form-group"><label for="topicAvailability2">Availability</label><select class="cust-select form-control mb-0" name="availability[]" data-new="' +
        data_new + '" onchange="toggleInput(' + data_new +
        ')"> <option value="">Select Availability</option>  <option value="yes">Yes</option><option value="no">No</option><option value="inprocess">In-Process</option></select></div></div>';
    htmlcontent +=
        '<div class="col-md-2"><div class="form-group"><label for="topicAvailability2">Files</label><input type="file" class="form-control" name="files[]" accept=".pdf,.xlsx,.doc,.docx" data-new="' +
        data_new + '"></div></div>';
    htmlcontent +=
        '<div class="col-md-3"><div class="form-group"><label for="topicAvailability2" readonly>Files To be uploaded</label><textarea name="reasons[]" id="reasons" rows="3" class="form-control" data-new="' +
        data_new +
        '"></textarea> <button class="form-control btn btn-outline-secondary mt-2 w-50" style="font-size:0.8em" id="edit_gen_pdf" data-new="' +
        data_new + '"  onclick="genPdf(' + data_new + ');">Edit & Generate PDF</button></div></div>';

    htmlcontent +=
        '<div class="col-md-2"><a href="javascript:void(0);" onclick="removeNewRow1();" class="btn btn-danger btn-sm"><i class="mdi mdi-minus"></i></a></div></div></div>';

    $('.addButtonCheckbox1:last').after(htmlcontent);
}
//make input field required if yes
function toggleInput(val) {
    var selectedValue = $("select[data-new='" + val + "']").val();
    var fileInput = $("input[type='file'][data-new='" + val + "']");

    if (selectedValue === "yes") {
        fileInput.prop("required", true);
    } else {
        fileInput.prop("required", false);
    }
}

// remove add row 
function removeNewRow1() {
    $(".addButtonCheckbox1:last").remove();
}
// get data from chat gtp
function chat_gtp(val, data_new) {
    var lev1 = $('#text_1').val();
    var lev2 = $('#text_2').val();
    var lev3 = $('#text_3').val();
    var lev4 = $('#text_4').val();
    // alert(lev1);
    var data = {
        "message": "Create a demo document file for this " + lev1 + lev2 + lev3 + lev4 + " Document title " + val,
    };
    $('#reasons[data-new="' + data_new + '"]').val('please wait ! we are getting details !!');
    var path = "/chat";
    $.ajax({
        url: path,
        data: data,
        success: function (result) {
            // console.log(result);
            $('#reasons[data-new="' + data_new + '"]').val(result);
        }
    });
}
function genPdf(data_new) {

    var lev1 = $('#lev_1').val();
    var lev2 = $('#lev_2').val();
    var lev3 = $('#lev_3').val();
    var lev4 = $('#lev_4').val();
    var menu_id;

    if (lev4) {
        menu_id = lev4;
    } else if (lev3) {
        menu_id = lev3;
    } else if (lev2) {
        menu_id = lev2;
    } else if (lev1) {
        menu_id = lev1;
    }
    // Set the value of the input field
    $('#menu_id_pdf').val(menu_id);
    $('#doc_id_pdf').val(data_new);

    var descriptionText = $('#reasons[data-new="' + data_new + '"]').val();
    var descriptionHTML = descriptionText.replace(/\n/g, '<br>'); // Replace newline characters with <br> tags
    $('#html_content').summernote('code', descriptionHTML); // Set HTML content using Summernote
    $('#generatePdf').on('shown.bs.modal', function () {
        $(this).find('.html_content').css('margin-top', '50px !important');
    });

    $('#generatePdf').modal('show');
}
// Add this function to your JavaScript code
function generate_pdf1() {
    // Get the HTML content from the textarea
    var htmlContent = document.getElementById('html_content').value;

    // Configure the PDF options
    var pdfOptions = {
        margin: 10,
        filename: 'generated_pdf.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
    };

    // Create a promise to handle the PDF generation with blob output
    var pdfPromise = html2pdf().from(htmlContent).set(pdfOptions).outputPdf('blob');

    // When the promise resolves, create a download link and trigger the download
    pdfPromise.then(function (blob) {
        var a = document.createElement('a');
        a.style.display = 'none';
        document.body.appendChild(a);

        var url = window.URL.createObjectURL(blob);
        a.href = url;
        a.download = 'generated_pdf.pdf';
        a.click();

        window.URL.revokeObjectURL(url);
    });
}

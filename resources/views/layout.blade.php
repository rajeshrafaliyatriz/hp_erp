<!-- resources/views/layouts/layout.blade.php -->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon.png">
    <title>TRIZ ERP</title>

    <!-- Bootstrap Core CSS -->

    <!-- Calendar CSS -->
    <link href="{{ asset('/plugins/bower_components/calendar/dist/fullcalendar.css') }}" rel="stylesheet">
    <!-- animation CSS -->

    <link href="{{ asset('/admin_dep/css/bootstrap.css') }}" rel="stylesheet">
    <link href="{{ asset('/admin_dep/css/bootstrap-select.css') }}" rel="stylesheet">
    <link href="{{ asset('/admin_dep/css/bootstrap-datepicker.min.css') }}" rel="stylesheet">

    <link href="{{ asset('/admin_dep/css/docs.css') }}" rel="stylesheet">
    <link href="{{ asset('/admin_dep/css/css3.css') }}" rel="stylesheet">
    <link href="{{ asset('/admin_dep/css/fontawesome.css') }}" rel="stylesheet">
    <link href="{{ asset('/admin_dep/css/materialdesignicons.min.css') }}" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="{{ asset('/admin_dep/css/elements.css') }}" rel="stylesheet">

    <link href="{{ asset('/plugins/bower_components/toast-master/css/jquery.toast.css') }}" rel="stylesheet">

    <link href="https://cdn.datatables.net/buttons/1.5.6/css/buttons.dataTables.min.css" rel="stylesheet"
        type="text/css" />
    <link rel="stylesheet" href="../../../tooltip/enjoyhint/jquery.enjoyhint.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.1/css/all.min.css"
        integrity="sha512-5Hs3dF2AEPkpNAR7UiOHba+lRSJNeM2ECkwxUIxC1Q/FLycGTbNapWXB4tP889k5T5Ju8fs4b1P5z/iB4nMfSQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="{{ asset('/assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('/assets/css/style_lms.css') }}" rel="stylesheet">
    <link href="https://code.jquery.com/ui/1.10.4/themes/ui-lightness/jquery-ui.css" rel="preload" as="style"
        onload="this.onload=null;this.rel='stylesheet'">

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</head>

<body>

    <main style="width:100%;overflow:hidden;">
        @yield('content')
    </main>

    <script src="{{ asset('/admin_dep/js/ajax.js') }}" defer></script>
    <script src="{{ asset('/admin_dep/js/popper.min.js') }}" defer></script>
    <script src="{{ asset('/admin_dep/js/custom.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts" defer></script>


    <script src="{{ asset('/plugins/bower_components/chartist-js/dist/chartist.min.js') }}" defer></script>
    <script
        src="{{ asset('/plugins/bower_components/chartist-plugin-tooltip-master/dist/chartist-plugin-tooltip.min.js') }}"
        defer></script>
    <!-- Sparkline chart JavaScript -->
    <script src="{{ asset('/plugins/bower_components/jquery-sparkline/jquery.sparkline.min.js') }}" defer></script>

    <script src="{{ asset('/plugins/bower_components/jquery.easy-pie-chart/dist/jquery.easypiechart.min.js') }}" defer>
    </script>
    <script src="{{ asset('/plugins/bower_components/jquery.easy-pie-chart/easy-pie-chart.init.js') }}" defer></script>
    <script src="{{ asset('/plugins/bower_components/bootstrap-datepicker/bootstrap-datepicker.min.js') }}"></script>
    <script src="{{ asset('/admin_dep/js/jquery-3.5.1.min.js') }}"></script>

    <script src="https://code.jquery.com/jquery-1.10.2.js"></script>
    <script src="{{ asset('/admin_dep/js/jquery-ui.js') }}" defer></script>

    <script src="{{ asset('/admin_dep/js/bootstrap.min.js') }}" defer></script>
    <script src="{{ asset('/admin_dep/js/generativeAI.js') }}" defer></script>
    <script src="{{ asset('/admin_dep/js/bootstrap-select.min.js') }}" defer></script>

    <script src="https://code.jquery.com/jquery-3.7.1.js" integrity="sha256-eKhayi8LEQwp4NKxN+CfCh+3qOVUtJn3QNZ0TciWLP4="
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO" crossorigin="anonymous">
    </script>
    <!-- jQuery (must be first) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- DataTables core -->
    <script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>

    <!-- DataTables Bootstrap (if you're using Bootstrap styles) -->
    <script src="https://cdn.datatables.net/1.10.19/js/dataTables.bootstrap.min.js"></script>

    <!-- DataTables Buttons and related dependencies -->
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/buttons.flash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/buttons.print.min.js"></script>
    <script>
        $(document).ready(function() {
            // This part handles initial formatting of dates from YYYY-MM-DD to DD-MM-YYYY
            $('.mydatepicker').each(function() {
                $(this).attr("placeholder", "dd-mm-yyyy");
                var selected_date = $(this).val();
                if (selected_date !== "" && selected_date !== "0000-00-00") {
                    var dateParts = selected_date.split('-'); // Assuming YYYY-MM-DD from server
                    if (dateParts.length === 3) {
                        var year = dateParts[0];
                        var month = dateParts[1];
                        var day = dateParts[2];
                        $(this).val(`${day}-${month}-${year}`);
                    }
                }
            });

            // Initialize datepickers for elements with class 'mydatepicker' and id 'datepicker'
            // This will make them pop up on click/focus and auto-close.
            jQuery('.mydatepicker, #datepicker').datepicker({
                changeMonth: true,
                changeYear: true,
                yearRange: "-74:+10", // Example: 74 years back, 10 years forward
                // REMOVED 'inline: true' - this is what makes it a popup
                autoclose: true, // This will close the calendar when a date is selected or input loses focus
                format: 'dd-mm-yyyy', // Display format
                orientation: 'bottom', // Position the calendar below the input
                forceParse: false // Prevents parsing invalid dates, allows user to type
            });

            // Specific datepicker initializations for other elements if they have different needs
            jQuery('#datepicker-autoclose').datepicker({
                autoclose: true,
                todayHighlight: true
            });

            // jQuery('#date-range').datepicker({
            //     toggleActive: true
            // });

            // If '#datepicker-inline' is truly meant to be inline, keep its inline: true
            // If you want it to also be a popup, remove 'inline: true' from here too.
            jQuery('#datepicker-inline').datepicker({
                todayHighlight: true
            });
        });
    </script>
</body>

</html>

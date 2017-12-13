$(function () {
    $('#report-start').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false 
    });
    $('#report-end').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false //Important! See issue #1075
    });
    $("#report-start").on("dp.change", function (e) {
        $('#report-end').data("DateTimePicker").minDate(e.date);
    });
    $("#report-end").on("dp.change", function (e) {
        $('#report-start').data("DateTimePicker").maxDate(e.date);
    });
});

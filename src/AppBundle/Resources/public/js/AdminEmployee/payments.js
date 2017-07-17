$(function () {
    $('#payments').datetimepicker({
        format: "MM-YYYY",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false 
    }).on('dp.change', function (e) {
        var date = new Date(e.date);
        var month = date.getMonth() + 1;
        var url = '/admin/payments/' + date.getFullYear() + '/' + month;
        $('#payments-form').attr("action", url);
    });
});

$(function () {
    $('#banking').datetimepicker({
        format: "MM-YYYY",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false 
    }).on('dp.change', function (e) {
        var date = new Date(e.date);
        var month = date.getMonth() + 1;
        var url = '/admin/banking/' + date.getFullYear() + '/' + month;
        $('#banking-form').attr("action", url);
    });
});

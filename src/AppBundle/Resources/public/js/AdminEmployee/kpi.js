
$(function () {
    $('[data-toggle="popover"]').popover();

    $('#kpi').datetimepicker({
        format: "DD-MM-YYYY",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false
    }).on('dp.change', function (e) {
        var date = new Date(e.date);
        var month = date.getMonth() + 1;
        var url = '/admin/kpi/' + date.getFullYear() + '-' + month + '-' + date.getDate();
        $('#kpi-form').attr("action", url);
    });
});

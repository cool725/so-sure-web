// datepicker-month.js

// require('../../../sass/pages/admin.scss');

// Require BS component(s)
// require('bootstrap/js/dist/tooltip');

// Require components
require('tempusdominus-bootstrap-4');

$(function(){

    // https://tempusdominus.github.io/bootstrap-4/Usage/
    $('#date_time_picker').datetimepicker({
        useCurrent: true,
        format: "DD-MM-YYYY",
    });

    $('#date_time_picker').on('change.datetimepicker', function(e) {
        let date  = new Date(e.date),
            month = date.getMonth() + 1,
            url = '/admin/kpi/' + date.getFullYear() + '-' + month + '-' + date.getDate();
        $('#month_form').attr('action', url);
    });

});

// admin.js

require('../../../sass/pages/admin.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
require('tempusdominus-bootstrap-4');

$(function(){

    $('[data-toggle="tooltip"]').tooltip();

    // https://tempusdominus.github.io/bootstrap-4/Usage/
    $('#date_time_picker').datetimepicker({
        format: "MM-YYYY",
    });

    $('#date_time_picker').on('change.datetimepicker', function(e) {
        let date  = new Date(e.date),
            month = date.getMonth() + 1,
            url   = $(this).data('url') + '/' + date.getFullYear() + '/' + month;
        $('#month_form').attr('action', url);
    });

});

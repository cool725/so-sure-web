// admin.js

require('../../../sass/pages/admin.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
require('tempusdominus-bootstrap-4');

$(function(){

    $('[data-toggle="tooltip"]').tooltip();

    $('#date_time_picker').datetimepicker({
        format: 'L'
    });

    $('#date_time_picker').on('change', function(e) {
        let date  = new Date(e.date),
            month = date.getMonth() + 1,
            url   = $(this).data('url') + '/' + date.getFullYear() + '/' + month;
        $('.month-form').attr('action', url);
    });

});

// datepicker-day-time.js

// require('../../../sass/pages/admin.scss');

// Require BS component(s)
// require('bootstrap/js/dist/tooltip');

// Require components
require('tempusdominus-bootstrap-4');

$(function(){

    // https://tempusdominus.github.io/bootstrap-4/Usage/
    $('.date-time-picker').datetimepicker({
        useCurrent: false,
        format: "DD-MM-YYYY HH:mm",
    });

});

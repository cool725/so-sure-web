// accounts.js

// require('../../../sass/pages/admin.scss');

// Require BS component(s)
// require('bootstrap/js/dist/tooltip');

// Require components
// require('tempusdominus-bootstrap-4');

$(function(){
    $('#salva_form_export').on('click', function() {
        return confirm('Are you sure you wish to re-export last month\'s Salva Payments csv file to S3?');
    });
    $('#helvetia_form_export').on('click', function() {
        return confirm('Are you sure you wish to re-export last month\'s Helvetia Payments csv file to S3?');
    });
});

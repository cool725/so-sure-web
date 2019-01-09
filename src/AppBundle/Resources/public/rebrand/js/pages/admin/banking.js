// banking.js

// require('../../../sass/pages/admin.scss');

// Require BS component(s)
// require('bootstrap/js/dist/tooltip');

// Require components
// require('tempusdominus-bootstrap-4');

$(function(){

    $('.delete-file').on('click', function(event) {
        e.preventDefault();

        let url = $(this).data('delete-url');
        if (confirm('Are you sure you wish to delete this file?')) {
            $.ajax({
                url: url,
                type: 'GET',
                success: function (result) {
                    window.location.reload(true);
                },
                failure: function (result) {
                    alert(result);
                }
            });
        }
    });

});

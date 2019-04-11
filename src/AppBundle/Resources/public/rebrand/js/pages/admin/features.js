// features.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
// e.g. require('../components/banner.js');

$(function(){

    $('.feature').on('click', function(e) {
        e.preventDefault();

        let feature = $(this).data('feature');

        if (confirm('Are you sure you want to change the state of the "' + feature + '" flag?')) {
            let url = $(this).data('active'),
                token = $(this).data('token');
            $.ajax({
                url: url,
                type: 'POST',
                data: { token: token },
            })
            .done(function(result) {
                window.location.reload(false);
            })
            .fail(function() {
                alert('Somethhing went wrong');
            })
        }
    });

});

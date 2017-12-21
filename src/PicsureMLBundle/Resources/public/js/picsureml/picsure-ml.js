$(function() {

    $('.picsure-ml-sync').click(function() {
        if (confirm('Are you sure you want to sync the pic-sure images?')) {
            var url = $(this).data('active');
            var token = $(this).data('token');
            $.ajax({
                url: url,
                type: 'POST',
                data: { token: token },
                success: function(result) {
                    window.location = window.location;
                }
            });
        }
    });

    $('.picsure-ml-annotate').click(function() {
        if (confirm('Are you sure you want to generate the annotations?')) {
            var url = $(this).data('active');
            var token = $(this).data('token');
            $.ajax({
                url: url,
                type: 'POST',
                data: { token: token },
                success: function(result) {
                    window.location = window.location;
                }
            });
        }
    });     

});

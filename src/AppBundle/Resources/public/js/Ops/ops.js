$(function() {

    $('#clearcookies').click(function() {
        Cookies.remove('cookieconsent_status');
        $(this).attr('disabled', true);
        window.location.reload(true);
    });

});

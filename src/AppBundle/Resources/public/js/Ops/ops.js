$(function() {

    $('#clearcookies').click(function() {
        Cookies.remove('cookieconsent_status');
        $(this).attr('disabled', true);
        window.location.reload(true);
    });

    $('#bearer-submit').click(function() {
        $.ajax({
            url: "/bearer-api/v1/user",
            headers: {"Authorization": "Bearer " + $('#bearer-token').val()}
        })
        .done(function (data) {
            alert(JSON.stringify(data));
        })
        .fail(function (jqXHR, textStatus) {
            alert("error: " + textStatus);
        });
    });
});

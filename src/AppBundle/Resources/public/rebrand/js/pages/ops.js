// ops.js

require('../../sass/pages/ops.scss');

// Require BS component(s)
// require('bootstrap/js/dist/carousel');

// Require components
import Cookie from "js.cookie";

$(function() {

    $('#clear_cookies').on('click', function(e) {
        e.preventDefault();
        Cookie.remove('cookieconsent_status');
        $(this).attr('disabled', true);
        window.location.reload(true);
    });

    $('#bearer_submit').on('click', function(e) {
        e.preventDefault();
        $.ajax({
            url: "/bearer-api/v1/user",
            headers: {"Authorization": "Bearer " + $('#bearer_token').val()}
        })
        .done(function (data) {
            alert(JSON.stringify(data));
        })
        .fail(function (jqXHR, textStatus) {
            alert("error: " + textStatus);
        });
    });

});

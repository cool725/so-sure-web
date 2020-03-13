// dev.js

$('#dev-reload').on('click', function(e) {
    e.preventDefault();
    window.location.reload(true);
});

$(document).keydown(function(e) {
    if(e.shiftKey && e.which == 71) {
        $('#dev_bar').toggleClass('hideme');
    };
});

$(function(){

    // Copy button on scode
    var clipboard = new Clipboard('.btn-copy');

    $('.btn-copy').tooltip({
        'title':'Copied',
        'trigger':'manual'
    });

    $('.btn-copy').click(function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(event) {
        // console.log(event);
        $('.btn-copy').tooltip('show');
        setTimeout(function() { $('.btn-copy').tooltip('hide'); }, 1500);
    });

    if ($('#welcome-modal').length) {
        $('#welcome-modal').modal('show');
    }
});

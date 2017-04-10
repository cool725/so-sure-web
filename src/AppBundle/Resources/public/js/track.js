function sosuretrack(name, callback) {
    var url = '/ops/track/' + name;
    $.get(url, callback);
    // console.log(url);
}

function sosuretrackinvite(name, callback) {
    var url = '/ops/track/invite/' + name;
    $.get(url, callback);
}

$(function(){
    $('.sosure-track').on('click', function(event) {
        event.preventDefault();
        var name = $(this).data('event');
        var url = $(this).data('event-url'); 
        sosuretrack(name, function() {
            if (url) {   
                window.location = url;
            }
        });
    })
});
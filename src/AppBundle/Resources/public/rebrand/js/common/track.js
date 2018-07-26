// track.js

let byName = function(name, callback) {
    let url = '/ops/track/' + name;
    $.get(url, callback);
};

let byInvite = function (name, callback) {
    let url = '/ops/track/invite/' + name;
    $.get(url, callback);
};


$(function() {

    $('.sosure-track').on('click', function(event) {
        event.preventDefault();
        var name = $(this).data('event');
        var url = $(this).data('event-url');
        byName(name, function() {
            if (url) {
                window.location = url;
            }
        });
    });

    $('.sosure-track-intercom').on('click', function(event) {
        event.preventDefault();
        var name = $(this).data('event');
        if (typeof Intercom !== 'undefined') {
            Intercom('trackEvent', name);
        }
    });

});

// track.js

let trackByName = function(name, callback) {
    let url = '/ops/track/' + name;
    $.get(url).always(callback);
};

let trackByInvite = function (name, callback) {
    let url = '/ops/track/invite/' + name;
    $.get(url).always(callback);
};

export default trackByName;

$(function() {

    $('.sosure-track').on('click', function(event) {
        event.preventDefault();
        var name = $(this).data('event');
        var url = $(this).data('event-url');
        trackByName(name, function() {
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

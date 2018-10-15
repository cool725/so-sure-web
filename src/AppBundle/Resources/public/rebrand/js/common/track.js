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

    $('.sosure-track').on('click', function(e) {

        let name  = $(this).data('event');
        let url   = $(this).data('event-url');
        let blank = $(this).data('event-blank');

        if (!blank) {
            // if not true prevent default behavior
            e.preventDefault();
        }

        trackByName(name, function() {
            if (url && blank) {
                // Do nothing
            } else if (url) {
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

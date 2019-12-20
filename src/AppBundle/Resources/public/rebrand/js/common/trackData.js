// trackData.js

const tracking = (name, type, location, callback) => {
    let url;

    // Track by invite & location
    if (type == 'invite') {
        url = '/ops/track/invite/' + name + '/' + location;

    // Track by scode used & location
    } else if (type == 'scodecopied') {
        url = '/ops/track/scodecopied/' + location;

    // Track by onboarding & name
    } else if (type == 'onboarding') {
        url = '/ops/track/onboarding/' + location;

    // Track by competition & name
    } else if (type == 'competition') {
        url = '/ops/track/onboarding/' + location;

    // Track name
    } else {
        // Default
        url = '/ops/track/' + name;
    }

    $.get(url).always(callback);
}

export default tracking;

$(function() {

    $('.track-data').on('click', function(e) {

        let name     = $(this).data('event'),
            url      = $(this).data('event-url'),
            blank    = $(this).data('event-blank'),
            location = $(this).data('event-location'),
            type     = $(this).data('event-type'),
            once     = $(this).data('event-once');

        if (!blank) {
            // if not true prevent default behavior
            e.preventDefault();
        }

        if (once != true) {
            tracking(name, type, location, function() {

                if (url && !blank) {
                    window.location = url;
                }
            });
        }

        if (typeof once) {
            $(this).data('event-once', '1');
        }

    });
});


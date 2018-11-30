// trackData.js

const tracking = (name, type, location, callback) => {
    let url;

    if (type == 'invite') {
        // If by invite
        url = '/ops/track/invite/' + name;
    } else if (type == 'location') {
        // If location
        url = '/ops/track/location/' + location + '/' + name;
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
            type     = $(this).data('event-type');

        if (!blank) {
            // if not true prevent default behavior
            e.preventDefault();
        }

        tracking(name, type, location, function() {

            if (url && !blank) {
                window.location = url;
            }
        });

    });
});


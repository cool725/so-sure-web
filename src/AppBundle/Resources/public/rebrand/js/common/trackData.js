// trackData.js

const tracking = (name, type, callback) => {
    let url;

    if (type == 'invite') {
        url = '/ops/track/invite/' + name;
    } else if (type == 'newtype') {
        url = '/ops/track/newtype/' + name;
    } else {
        url = '/ops/track/' + name;
    }

    $.get(url).always(callback);
}

export default tracking;

$(function() {

    $('.track-data').on('click', function(e) {

        let name  = $(this).data('event'),
            url   = $(this).data('event-url'),
            blank = $(this).data('event-blank'),
            type  = $(this).data('event-type');

        if (!blank) {
            // if not true prevent default behavior
            e.preventDefault();
        }

        tracking(name, type, function() {
            if (url && !blank) {
                window.location = url;
            }
        });

    });
});


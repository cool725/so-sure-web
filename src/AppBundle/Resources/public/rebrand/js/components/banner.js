// banner.js

import trackByName from '../common/track.js';

$(function() {

    const close = $('.banner__close');

    close.on('click', function(e) {

        // Add tracking
        let name = $(this).data('event');
        trackByName(name, function() {
            close.parent().slideUp();
        });

    });
});

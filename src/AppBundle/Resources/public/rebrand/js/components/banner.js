// banner.js

import tracking from '../common/track-data.js';

$(function() {

    const close = $('.banner__close');

    close.on('click', function(e) {

        // Add tracking
        let name = $(this).data('event');

        tracking(name);

        $(this).parent().slideUp();

    });
});

// banner.js

import tracking from '../common/trackData.js';

$(function() {

    const close = $('.banner__close');

    close.on('click', function(e) {

        // Add tracking
        let name = $(this).data('event');

        tracking(name, function() {
             close.parent().slideUp();
        });

    });
});

// modalVideo.js

import tracking from '../common/trackData.js';

$(function() {

    let videoSrc = null;

    $('.watch-video').on('click', function(e) {
        e.preventDefault();

        // Get source from data-src attr
        videoSrc = $(this).data('src');

        // Add tracking
        // Callback stops autoplay *sigh*
        let name = $(this).data('event');
        tracking(name);

        // Set source of iframe
        $('#sosure-video-modal-embed').attr('src',videoSrc + '?rel=0&amp;showinfo=0&amp;modestbranding=1&amp;autoplay=1');
    });


    $('#sosure-video-modal').on('hide.bs.modal', function (e) {
        // Reset the src minus the autoplay param as we have no control - this simulates stopping it!
        $('#sosure-video-modal-embed').attr('src',videoSrc);
    })
});




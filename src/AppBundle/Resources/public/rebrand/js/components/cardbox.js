// cardbox.js

$(function() {

    const cardbox = $('.expanding-cardbox'),
          title   = $('.expanding-cardbox__title'),
          remote  = $('a[data-cardbox-target]');

    // Common action
    title.on('click', function(e) {
        e.preventDefault();

        let next = $(this).parent().parent().siblings('.expanding-cardbox');

        next.removeClass('expanding-cardbox__open')
        .find('.expanding-cardbox__expand, .expanding-cardbox__excerpt')
        .hide('fast');

        // Toggle the content
        $(this).parent().parent().toggleClass('expanding-cardbox__open')
        .find('.expanding-cardbox__expand, .expanding-cardbox__excerpt')
        .slideToggle('fast');

        // Adjust the arrow
        next.find(title).find('.far').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        $(this).find('.far').toggleClass('fa-chevron-up');

        // Adjust the arrow
        next.find(title).find('.fas').removeClass('fa-arrow-circle-up').addClass('fa-arrow-circle-down');
        $(this).find('.fas').toggleClass('fa-arrow-circle-up');

    });

    // Special case - link to another box and scroll to that box
    remote.on('click', function(e) {
        e.preventDefault();

        let target = $(this).data('cardbox-target');
        let anchor = $(this).data('cardbox-anchor');

        $(target).toggleClass('expanding-cardbox__open')
        .find('.expanding-cardbox__expand, .expanding-cardbox__excerpt')
        .slideToggle('fast');

        $(target).find('.far').toggleClass('fa-chevron-up');

        $('html, body').animate({
            scrollTop: $(anchor).offset().top - 200
        }, 1500);
    });
});

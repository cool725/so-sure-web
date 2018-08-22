// cardbox.js

$(function() {

    const cardbox = $('.expanding-cardbox'),
          title   = $('.expanding-cardbox__title');

    let open = false;

    title.on('click', function(e) {
        e.preventDefault();

        // Toggle the content
        $(this).parent().parent().toggleClass('expanding-cardbox__open')
        .find('.expanding-cardbox__expand, .expanding-cardbox__excerpt')
        .slideToggle('fast');

        // Adjust the arrow
        $(this).find('.far').toggleClass('fa-chevron-up');
    });

});

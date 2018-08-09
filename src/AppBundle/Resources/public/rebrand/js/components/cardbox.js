// cardbox.js

$(function() {

    const cardbox = $('.expanding-cardbox');

    cardbox.on('click', function(e) {
        e.preventDefault();

        // Toggle the content
        $(this).toggleClass('expanding-cardbox__open')
        .find('.expanding-cardbox__expand, .expanding-cardbox__excerpt')
        .slideToggle('fast');

        // Adjust the arrow
        $(this).find('.fas').toggleClass('fa-chevron-up');
    });

});

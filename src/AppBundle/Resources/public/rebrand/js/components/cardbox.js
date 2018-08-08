// cardbox.js

$(function() {

    const cardbox = $('.expanding-cardbox');

    cardbox.on('click', function(e) {
        e.preventDefault();

        $(this).toggleClass('expanding-cardbox__open')
        .find('.expanding-cardbox__expand')
        .slideToggle('fast');

        $(this).find('.fas').toggleClass('fa-chevron-up');
    });

});

// scroll-to.js

$('.scroll-to').on('click', function(e) {
    e.preventDefault();

    let anchor = $(this).data('scroll-to-anchor'),
        offset = 0;

    if ($(this).data('scroll-to-offset')) {
        offset = $(this).data('scroll-to-offset');
    }

    $('html, body').animate({
        scrollTop: $(anchor).offset().top - offset
    }, 1500);

});

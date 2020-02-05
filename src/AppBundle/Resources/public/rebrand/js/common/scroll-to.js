// scroll-to.js

$('.scroll-to').on('click', function(e) {
    e.preventDefault();

    let anchor = $(this).data('scroll-to-anchor');

    $('html, body').animate({
        scrollTop: $(anchor).offset().top
    }, 1500);

});

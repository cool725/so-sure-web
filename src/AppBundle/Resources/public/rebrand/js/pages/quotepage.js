// quotepage.js

require('../../sass/pages/quotepage.scss');

$(function() {

    $('.toggle-text[data-toggle="collapse"]').on('click', function(e){
        e.preventDefault();

        $(this)
        .data('text-original', $(this).html())
        .html($(this).data('text-swap') )
        .data('text-swap', $(this).data('text-original'));

        // Scroll to content
        $('.quote__details__container').animate({
            scrollTop: ($(this).offset().top - 70)
        }, 500);
    });

    $('.quote__details__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

    // JULES CRAP
    $('#fix-get-insured').on('click', function(e){
        $('form[name="buy_form"]').submit();
    });

});

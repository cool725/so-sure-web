// quotepage.js

require('../../sass/pages/quotepage.scss');
require('../components/table.js');

$(function() {

    $('.toggle-text[data-toggle="collapse"]').on('click', function(e){
        e.preventDefault();

        $(this)
        .data('text-original', $(this).html())
        .html($(this).data('text-swap') )
        .data('text-swap', $(this).data('text-original'));

        // // Scroll to content
        // $('.quote__details__container').animate({
        //     scrollTop: ($(this).offset().top - 70)
        // }, 500);
    });

    // $('.quote__details__container').scroll(function(e) {
    //     $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    // });

    // TODO: Use form builder to add extra button
    $('#fix-get-insured').on('click', function(e){
        e.preventDefault();
        $('form[name="buy_form"]').submit();
    });

});

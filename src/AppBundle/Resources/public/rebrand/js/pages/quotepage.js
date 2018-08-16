// quotepage.js

require('../../sass/pages/quotepage.scss');

$(function() {

    $('.toggle-text[data-toggle="collapse"]').on('click', function(e){
        e.preventDefault();
        $(this)
        .data('text-original', $(this).html())
        .html($(this).data('text-swap') )
        .data('text-swap', $(this).data('text-original'));
    });

    $('.quote__details__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

});

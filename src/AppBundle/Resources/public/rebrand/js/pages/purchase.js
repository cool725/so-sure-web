// purchase.js

require('../../sass/pages/purchase.scss');

// Require BS component(s)
require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('../components/table.js');

$(function() {

    $('#qpw-info-btn').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: $('.qpw__sub')[0].scrollHeight
        }, 500);
    });

    // As fixed page on desktop init scroll effect on main container scroll
    $('.qpw__main__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });
});

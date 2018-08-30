// quotepage.js

require('../../sass/pages/quotepage.scss');
require('../components/table.js');

$(function() {

    $('#qpw-info-btn').on('click', function(e) {
        e.preventDefault();

        // Toggle sticky quote so we can scroll
        $('.qpw__main__container').toggleClass('qpw__main__container-unstuck');

        // Toggle body class so navbar effect works
        $('body').toggleClass('quote-scroll');

        // Toggle back when we close to show the logo again
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

    // As fixed page on desktop init scroll effect on main container scroll
    $('.qpw__main__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

});

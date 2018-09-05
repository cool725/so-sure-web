// quotepage.js

require('../../sass/pages/quotepage.scss');

// Require BS component(s)
require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');

$(function() {

    // As fixed page on desktop init scroll effect on main container scroll
    $('.qpw__main__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

});

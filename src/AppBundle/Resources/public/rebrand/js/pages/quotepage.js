// quotepage.js

require('../../sass/pages/quotepage.scss');

// Require BS component(s)
// require('bootstrap/js/dist/modal');
// require('bootstrap/js/dist/dropdown');
require('bootstrap/js/dist/tooltip');

// Require components
require('../components/table.js');
let textFit = require('textfit');

$(function() {

    textFit($('.fit')[0], {detectMultiLine: false});

    // As fixed page on desktop init scroll effect on main container scroll
    $('.qpw__main__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

});

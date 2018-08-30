// purchase.js

require('../../sass/pages/purchase.scss');

// Require components
require('dot');
require('corejs-typeahead/dist/bloodhound.js');
require('corejs-typeahead/dist/typeahead.jquery.js');
require('jquery-mask-plugin');
require('fuse.js');
require('jquery-validation');
require('../../../js/Default/jqueryValidatorMethods.js');
require('../../../js/Purchase/purchaseStepAddress.js');
require('../../../js/Purchase/purchaseStepPhoneNew.js');

$(function() {

    $('#qpw-info-btn').on('click', function(e) {
        e.preventDefault();

        // Toggle sticky quote so we can scroll
        $('.qpw__main__container').toggleClass('qpw__main__container-unstuck');

        // Toggle body class so navbar effect works
        $('body').toggleClass('quote-scroll');

        if ($('#qpw-info').is('.collapse')) {
            $('html, body').animate({
                scrollTop: $('.qpw__sub__container').offset().top
            }, 1500);
        }

        // Toggle back when we close to show the logo again
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

    // As fixed page on desktop init scroll effect on main container scroll
    $('.qpw__main__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });
});

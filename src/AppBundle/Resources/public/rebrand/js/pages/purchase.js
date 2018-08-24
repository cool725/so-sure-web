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

    $('.toggle-text[data-toggle="collapse"]').on('click', function(e) {
        e.preventDefault();

        $(this)
        .data('text-original', $(this).html())
        .html($(this).data('text-swap') )
        .data('text-swap', $(this).data('text-original'));

        // Scroll to content
        $('.purchase__details__container').animate({
            scrollTop: ($(this).offset().top - 70)
        }, 500);
    });

    $('.purchase__details__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

});

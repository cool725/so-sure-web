// phone-insurance-homepage.js

require('../../sass/pages/phone-insurance-homepage.scss');

// Require BS component(s)
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/tab');

// Require components
require('../components/table.js');
require('../components/modalVideo.js');
require('../components/phone-search-dropdown-card.js');
let textFit = require('textfit');

let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

$(function() {

    if ($('.fit').length) {
        textFit($('.fit'), {detectMultiLine: false});
    }

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height();
        knowledgeBaseD.css('top', -tabsHeight);

    // Reviews
    let $carousel = $('#customer_reviews').flickity({
        wrapAround: true,
        prevNextButtons: false,
        pageDots: false
    });

    $('.review__controls-prev').on('click', function(e) {
        $carousel.flickity('previous');
    });

    $('.review__controls-next').on('click', function(e) {
        $carousel.flickity('next');
    });

    setTimeout(function() {
        $('.toast-1').toast('show');
    }, 2000);

    $('.toast-1').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-2').toast('show');
        }, 200);
    })

    $('.toast-2').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-3').toast('show');
        }, 200);
    })

    $('.toast-3').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-4').toast('show');
        }, 200);
    })

    $('.toast-4').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-5').toast('show');
        }, 200);
    })
});



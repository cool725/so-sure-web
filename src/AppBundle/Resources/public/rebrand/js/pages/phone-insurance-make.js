// phone-insurance-make.js

require('../../sass/pages/phone-insurance-make.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/tab');

// Require components
require('../components/banner.js');
let textFit = require('textfit');
require('../components/table.js');
require('jquery-validation');
require('../common/validation-methods.js');
// require('../components/modalVideo.js');
require('../components/phone-search-dropdown-card.js');

let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

$(function() {

    // Textfit h1
    if ($('.fit').length) {
        textFit($('.fit'), {detectMultiLine: false});
    }

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

    let knowledgeBaseM = $('.knowledge-base__mobile'),
        cardboxHeight  = knowledgeBaseM.children(':first').height() / 2;
        knowledgeBaseM.css('top', -cardboxHeight);

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height();
        knowledgeBaseD.css('top', -tabsHeight);

    // Tabs - style the arrow if open
    // $('.tab-link').on('click', function(e) {
    //     let clicked = $(this);
    //     $('.tab-indicator').removeClass('fa-arrow-circle-up').addClass('fa-arrow-circle-down');
    //     clicked.find('.tab-indicator').removeClass('fa-arrow-circle-down').addClass('fa-arrow-circle-up');
    // });
});


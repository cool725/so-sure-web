// phone-insurance.js

require('../../sass/pages/phone-insurance.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/tab');

// Require components
require('../components/banner.js');
require('../components/phone-search-dropdown-card.js');
let textFit = require('textfit');
require('../components/table.js');

let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

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

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height() + 2;
        knowledgeBaseD.css('top', -tabsHeight);

    let knowledgeBaseM = $('.knowledge-base__mobile'),
        cardboxHeight  = knowledgeBaseM.children(':first').height() / 2;
        knowledgeBaseM.css('top', -cardboxHeight);

});


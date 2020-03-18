// homepage.js

require('../../sass/pages/homepage.scss');

// Require components
require('../components/table.js');
require('../components/modalVideo.js');
require('../components/phone-search-dropdown-card.js');

let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

$(function() {

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height() + 2;
        knowledgeBaseD.css('top', -tabsHeight);

    let knowledgeBaseM = $('.knowledge-base__mobile'),
        cardboxHeight  = knowledgeBaseM.children(':first').height() / 2;
        knowledgeBaseM.css('top', -cardboxHeight);

    // Tabs - style the arrow if open
    $('.tab-link').on('click', function (e) {
        $('.tab-indicator').removeClass('fa-arrow-circle-down')
                           .addClass('fa-arrow-circle-up');
        $(this).find('.fas').removeClass('fa-arrow-circle-up')
                            .addClass('fa-arrow-circle-down');
    });

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
});


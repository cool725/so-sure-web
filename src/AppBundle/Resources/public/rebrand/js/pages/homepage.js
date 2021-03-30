// homepage.js

require('../../sass/pages/homepage.scss');

require('../components/phone-search-dropdown-card.js');

let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

$(function() {

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


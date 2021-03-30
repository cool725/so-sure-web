// make-a-claim.js

require('../../sass/pages/make-a-claim.scss');

// Require BS component(s)

// Require components
let textFit = require('textfit');
let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

$(function(){

    // Reviews
    let $carousel = $('#claims_cards').flickity({
        prevNextButtons: false,
        pageDots: true,
        watchCSS: true
    });

    if ($('.fit').length) {
        textFit($('.fit'), {detectMultiLine: false});
    }

});

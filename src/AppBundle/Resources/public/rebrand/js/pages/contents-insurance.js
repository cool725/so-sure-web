// contents-insurance.js

require('../../sass/pages/contents-insurance.scss');

require('jquery-validation');
require('../common/validation-methods.js');
require('../components/modalVideo.js');

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
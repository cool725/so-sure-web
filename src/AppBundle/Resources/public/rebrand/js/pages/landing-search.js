// landing.js

require('../../sass/pages/landing-search.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');

// Require components
require('../components/table-v2.js');
require('../components/reviews-v2.js');
require('../components/phone-search-dropdown-card.js');
let textFit = require('textfit');

$(function() {

  // Textfit h1
  if ($('.fit').length) {
      textFit($('.fit'), {detectMultiLine: false});
  }
});
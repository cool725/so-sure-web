// promo.js

require('../../sass/pages/promo.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
// require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');

// Require components
require('../components/phone-search-dropdown-card.js');

let textFit = require('textfit');

$(function() {

    if ($('.fit').length) {
        textFit($('.fit'), {detectMultiLine: false});
    }
});

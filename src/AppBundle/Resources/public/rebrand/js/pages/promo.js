// promo.js

require('../../sass/pages/promo.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
// require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');

// Require components

let textFit = require('textfit');

$(function() {

    textFit($('.fit'), {detectMultiLine: false});
});

// homepage.js

require('../../sass/pages/homepage.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');

// Require components
require('../components/banner.js');
// require('textfit');
let textFit = require('textfit');

$(function() {
    textFit($('.fit')[0], {detectMultiLine: false});
});


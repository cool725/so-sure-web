// purchase-b.js

require('../../sass/pages/purchase-b.scss');

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
let textFit = require('textfit');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    // Fit title
    textFit($('.fit'), {detectMultiLine: false});

});

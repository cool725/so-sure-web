// rewardpot.js

require('../../sass/pages/social-insurance.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
// require('bootstrap/js/dist/modal');

// Require components
require('../components/modalVideo.js');

// Lazy load images
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

let textFit = require('textfit');

$(function() {
    textFit($('.fit')[0], {detectMultiLine: false});
});


// homepageB.js

require('../../sass/pages/homepageB.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');

// Require components
require('../components/phone-search-dropdown-card.js');
let textFit = require('textfit');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    textFit($('.fit'), {detectMultiLine: false});

});


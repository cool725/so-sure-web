// landing-snapchat-b.js

require('../../sass/pages/landing-snapchat-b.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');

// Require components
require('../components/table.js');
require('../components/banner.js');
let textFit = require('textfit');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();


$(function() {
    textFit($('.fit')[0], {
        minFontSize: 40,
        maxFontSize: 60
    });
});


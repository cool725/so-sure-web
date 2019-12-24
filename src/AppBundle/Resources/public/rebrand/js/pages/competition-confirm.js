// competition-confirm.js

require('../../sass/pages/competition-confirm.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
let textFit = require('textfit');
require('jquery-validation');
require('../common/validationMethods.js');
require('../components/phone-search-dropdown-card.js');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

// Clipboard
let Clipboard = require('clipboard');

import tracking from '../common/trackData.js';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    // Use textfit plugin for h1 tag
    textFit($('.fit'), {detectMultiLine: true});

    // Copy scode
    // NOTE: Copies from hidden div with a body of text
    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(e) {
        tracking('', 'competition', 'competition-copied-code');

        e.clearSelection();
    });

});

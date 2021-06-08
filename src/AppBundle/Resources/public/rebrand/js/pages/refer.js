// refer.js

require('../../sass/pages/refer.scss');

// Require BS component(s)
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
let Clipboard = require('clipboard');

import tracking from '../common/track-data.js';

$(function() {

    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(e) {
        $('.btn-copy').tooltip({'title':   'Copied 😀','trigger': 'manual'})
                      .tooltip('show');

        tracking('', 'scodecopied', 'refer-page', '');

        setTimeout(function() {
            $('.btn-copy').tooltip('hide');
        }, 1500);
    });
});

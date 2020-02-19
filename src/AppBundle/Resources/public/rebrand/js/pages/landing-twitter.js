// landing-twitter.js

require('../../sass/pages/landing-twitter.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');

// Require components
require('../components/table.js');
require('../components/banner.js');
let textFit = require('textfit');

$(function() {
    textFit($('.fit')[0], {
        minFontSize: 40,
        maxFontSize: 60
    });

    setTimeout(function() {
        $('.toast-1').toast('show');
    }, 2000);

    $('.toast-1').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-2').toast('show');
        }, 200);
    })

    $('.toast-2').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-3').toast('show');
        }, 200);
    })

    $('.toast-3').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-4').toast('show');
        }, 200);
    })

    $('.toast-4').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-5').toast('show');
        }, 200);
    })
});


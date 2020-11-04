// homepage-b.js

require('../../sass/pages/homepage-b.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/tab');

// Require components
require('../components/table.js');
require('../components/phone-search-combined.js');
require('../components/reviews-v2.js');

// Require extras
let textFit = require('textfit');

$(function() {

    if ($('.fit').length) {
        textFit($('.fit'), {detectMultiLine: false});
    }

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height() + 2;
        knowledgeBaseD.css('top', -tabsHeight);

    // Tabs - style the arrow if open
    $('.tab-link').on('click', function(e) {
        let clicked = $(this);
        $('.tab-indicator').removeClass('fa-arrow-circle-up').addClass('fa-arrow-circle-down');
        clicked.find('.tab-indicator').removeClass('fa-arrow-circle-down').addClass('fa-arrow-circle-up');
    });
});


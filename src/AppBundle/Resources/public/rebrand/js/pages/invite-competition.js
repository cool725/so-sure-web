// homepage.js

require('../../sass/pages/invite-competition.scss');

// Require BS component(s)
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/tab');

// Require components
let textFit = require('textfit');
require('../components/table.js');

$(function() {

    // Use textfit plugin for h1 tag
    textFit($('.fit'), {detectMultiLine: true});

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height() + 4;
        knowledgeBaseD.css('top', -tabsHeight);

    let knowledgeBaseM = $('.knowledge-base__mobile'),
        cardboxHeight  = knowledgeBaseM.children(':first').height() / 2;
        knowledgeBaseM.css('top', -cardboxHeight);

    // Tabs - style the arrow if open
    $('.tab-link').on('click', function (e) {
        $('.tab-indicator').removeClass('fa-arrow-circle-down')
                           .addClass('fa-arrow-circle-right');
        $(this).find('.fas').removeClass('fa-arrow-circle-right')
                            .addClass('fa-arrow-circle-down');
    });

});

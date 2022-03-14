// students-insurance.js

require('../../sass/pages/students-insurance.scss');

// Require BS component(s)
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/tab');

// Require components
let textFit = require('textfit');

$(function() {

  if ($('.fit').length) {
    textFit($('.fit'), {
      detectMultiLine: false
    });
  }
});
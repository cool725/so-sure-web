// upgrades.js

require('../../../sass/pages/user/upgrades.scss');

// Require BS component(s)
// require('bootstrap/js/dist/carousel');

// Require components
// require('../../common/checkout.js');
require('../../components/phone-search-dropdown-upgrade.js');

let textFit = require('textfit');

$(function() {

    textFit($('.fit'), {detectMultiLine: false});

});

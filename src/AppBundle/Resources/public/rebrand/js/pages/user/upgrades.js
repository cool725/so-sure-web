// upgrades.js

require('../../../sass/pages/user/upgrades.scss');

// Require BS component(s)

// Require components
require('../../components/phone-search-dropdown-upgrade.js');

let textFit = require('textfit');

$(function() {

    textFit($('.fit'), {detectMultiLine: false});

});

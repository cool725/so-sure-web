// welcome.js

require('../../sass/pages/welcome.scss');

// Require components
// require('clipboard/dist/clipboard.min.js');
let Clipboard = require('clipboard');

$(function() {

    // Open the policy summary on load
    $('#welcome_policy_summary').trigger('click');

    // Add copy btn for sharing link
    // new ClipboardJS('.btn-copy');
    let clipboard = new Clipboard('.btn-copy');

    $('.btn-copy').click(function(e) {
        e.preventDefault();
    });

});

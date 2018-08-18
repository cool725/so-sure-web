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

    clipboard.on('success', function(event) {
        console.log(event);
        $('.btn-copy').tooltip('show');
        setTimeout(function() { $('.btn-copy').tooltip('hide'); }, 1500);
    });

    $('.welcome__details__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });

});

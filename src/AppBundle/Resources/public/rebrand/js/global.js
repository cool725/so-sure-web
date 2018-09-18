// global.js

require('../sass/global.scss');

// Cookies
let CookieConsent = require('cookieconsent');

document.addEventListener('DOMContentLoaded', () => {
    cookieconsent.initialise({
        container: document.getElementsByTagName('body'),
        content: {
            message: 'This website uses cookies to ensure you get the best experience on our website',
            dismiss: 'Got it!',
            href: "https://www.iubenda.com/privacy-policy/7805295/cookie-policy"
        },
        theme: "classic",
        palette: {
            popup: {
                background: '#081B9A'
            },
            button: {
                background: "#fff",
                text: "#170B38"
            }
        }
    });
});

// Require BS component(s)
require('bootstrap/js/dist/alert');

// Require components
require('./common/track.js');
require('./common/fbLogin.js');
require('./common/toggleText.js');
require('./components/nav.js');
require('./components/getQuote.js');
require('./components/phoneSearchDropdown.js');
require('./components/select.js');
require('./components/cardbox.js');

$(function() {


});

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

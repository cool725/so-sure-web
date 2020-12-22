// get-quote.js

const bodyScrollLock = require('body-scroll-lock');
const disableBodyScroll = bodyScrollLock.disableBodyScroll;
const enableBodyScroll = bodyScrollLock.enableBodyScroll;

$(function() {

    const quoteToggle = $('.get-a-quote'),
          quoteModal  = $('.getquote'),
          menu        = $('#menu'),
          hamburger   = $('#nav_toggle'),
          logo        = $('.navbar-brand__logo');

    let open = false;

    const getQuote = () => {

        // If menu is open whilst clicking get quote, close the menu
        if ($(menu).hasClass('menu--open')) {
            $(hamburger).trigger('click');
        }

        // Fix for resizing select if phone in session
        if ($('body').is('.quote, .purchase, .phone-insurance-make-model, .phone-insurance-make, .home-instore, .phone-insurance-make-model-memory')) {
            $('.phone-search-dropdown__make, .phone-search-dropdown__model').resizeselect();
        }

        if (open) {
            disableBodyScroll(menu);
        } else {
            enableBodyScroll(menu);
        }

        // Toggle 'open' class
        quoteModal.toggleClass('getquote--open');
    }

    quoteToggle.on('click', function(e) {
        e.preventDefault();

        open = !open;

        getQuote(open);
    });

    // Escape key close
    $(document).keyup(function(e) {
        if (quoteModal.is('.getquote--open') && e.keyCode === 27) {

            open = !open;

            getQuote(open);
        }
    });

    if (window.location.search.indexOf('showquote=true') > -1) {
        open = !open;
        getQuote(open);
    }

});

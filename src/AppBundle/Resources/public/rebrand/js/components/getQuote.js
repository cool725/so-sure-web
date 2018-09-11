// getQuote.js

$(function() {

    const quoteToggle = $('.get-a-quote'),
          quoteModal  = $('.getquote'),
          menu        = $('#menu'),
          hamburger   = $('#nav_toggle'),
          logo        = $('.navbar-brand__logo');

    const getQuote = () => {

        // If menu is open whilst clicking get quote, close the menu
        if ($(menu).hasClass('menu--open')) {
            $(hamburger).trigger('click');
        }

        // If using lighter theme - Toggle logo class
        // TODO: this could all be done by class - refactor
        if (!$('.navbar').is('.navbar-light')) {
            logo.toggleClass('navbar-brand__logo-white-light');
        }

        // Fix for resizing select if phone in session
        if ($('body').is('.quote, .purchase')) {
            $('.phone-search-dropdown__make, .phone-search-dropdown__model').resizeselect();
        }

        // Prevent scrolling whilst open
        $('body').toggleClass('body--overflow');

        // Toggle 'open' class
        quoteModal.toggleClass('getquote--open');
    }

    // Trigger by class
    quoteToggle.on('click', function(e) {
        e.preventDefault();
        getQuote();
    });

    // Escape key close
    $(document).keyup(function(e) {
        if (quoteModal.is('.getquote--open') && e.keyCode === 27) {
            getQuote();
        }
    });

});

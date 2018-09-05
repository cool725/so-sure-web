// getQuote.js

$(function() {

    const quoteToggle = $('.get-a-quote'),
          quoteModal  = $('.getquote'),
          menu        = $('#menu'),
          hamburger   = $('#nav_toggle'),
          logo        = $('.navbar-brand__logo');

    quoteToggle.on('click', function(e) {
        e.preventDefault();

        if ($(menu).hasClass('menu--open')) {
            $(hamburger).trigger('click');
        }

        // Toggle logo class
        if (!$('body').is('.quote, .purchase')) {
            logo.toggleClass('navbar-brand__logo-white-light');
        }

        // Fix for resizing select if phone in session
        if ($('body').hasClass('quote')) {
            $('.phone-search-dropdown__make').resizeselect();
        }

        // Prevent scrolling whilst open
        $('body').toggleClass('body--overflow');

        quoteModal.toggleClass('getquote--open');
    });
});

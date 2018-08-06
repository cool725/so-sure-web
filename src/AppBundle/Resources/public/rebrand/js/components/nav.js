// nav.js

import usrAgnt from '../common/setAgent.js';

$(function() {

    const nav       = $('.navbar'),
          hamburger = $('#nav_toggle'),
          menu      = $('#menu'),
          logo      = $('.navbar-brand__logo'),
          quoteBtn  = $('#quote-btn');

    let open = false;

    // Toggle - Menu
    hamburger.on('click', function(e) {
        e.preventDefault();

        // Toggle active class to hamburger
        $(this).toggleClass('is-active');

        // Toggle menu open
        menu.toggleClass('menu--open');

        // Toggle logo class
        logo.toggleClass('navbar-brand__logo-white-light');

        // Toggle the quote btn class
        quoteBtn.toggleClass('btn-gradient btn-outline-white');

        // Prevent scrolling whilst open
        $('body').toggleClass('body--overflow');

        if (usrAgnt == 'iOS') {
            $('body').toggleClass('body--overflow_iOS');
        }
    });

    // Fix - For iOS to stop background scrolling
    if (usrAgnt == 'iOS') {

        $('.menu--open').on('touchmove', function(e) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        });
    }

    // Add navbar background if page reloads not at the top
    $(window).on('load', function(e) {
        if ($(this).scrollTop() > 5) {
            nav.addClass('navbar-scrolled');
        }
    });

    // Scroll - Add navbar background on scroll
    $(window).scroll(function(e) {
        nav.toggleClass('navbar-scrolled', $(this).scrollTop() > 5);
    });

});

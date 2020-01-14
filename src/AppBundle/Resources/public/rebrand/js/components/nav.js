// nav.js

// const bodyScrollLock = require('body-scroll-lock');
// const disableBodyScroll = bodyScrollLock.disableBodyScroll;
// const enableBodyScroll = bodyScrollLock.enableBodyScroll;

import usrAgnt from '../common/setAgent.js';

$(function() {

    const nav       = $('.navbar'),
          hamburger = $('#nav_toggle'),
          menu      = $('#menu'),
          logo      = $('.navbar-brand__logo'),
          btnSwap   = $('.btn-swap');

    let open = false;

    // Toggle - Menu
    hamburger.on('click', function(e) {
        e.preventDefault();

        open = !open;

        // Toggle active class to hamburger
        $(this).toggleClass('is-active');

        // Toggle menu open
        menu.toggleClass('menu--open');

        // Toggle the quote btn class
        btnSwap.toggleClass('hideme');

        // if (open) {
        //     disableBodyScroll(menu);
        // } else {
        //     enableBodyScroll(menu);
        // }
    });

    // Add navbar background if page reloads not at the top
    $(window).on('load', function(e) {
        if ($(this).scrollTop() > 5) {
            if (!$('body').is('.quote, .purchase, .welcome')) {
                nav.addClass('navbar-scrolled');
            } else {
                nav.addClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
            }
        }
    });

    // Scroll - Add navbar background on scroll
    $(window).scroll(function(e) {
        if (!$('body').is('.quote, .purchase, .welcome')) {
            nav.toggleClass('navbar-scrolled', $(this).scrollTop() > 5);
        } else {
            nav.toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
        }
    });

});

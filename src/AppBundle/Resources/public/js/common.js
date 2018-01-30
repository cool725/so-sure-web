// Common JS functions used throughout the site
var sosure = sosure || {};

sosure.globals = (function() {
    var self = {};
    self.device_category = null;

    self.init = function() {
        self.device_category = $('#ss-root').data('device-category');
    }

    return self;
})();

$(function(){
    sosure.globals.init();
});

$(function(){

    // SROLL TO
    // Add anchor via:         data-scroll-to-anchor
    // Add focus after anchor: data-scroll-to-focus
    // Add adjustment to pos:  data-scroll-to-adjust

    var adjust = 0;

    $('.scroll-to').click(function(e) {

        e.preventDefault();

        var anchor  = $(this).data('scroll-to-anchor');
        var focus   = $(this).data('scroll-to-focus');
        var adjust2 = adjust;

        if ($(this).data('scroll-to-adjust') !== undefined) {
            adjust2 = adjust + $(this).data('scroll-to-adjust');
        }

        $('html, body').animate({
            scrollTop: $(anchor).offset().top - adjust2
        }, 1500);

        // Unfocus the button!
        $(this).blur();

        if (typeof focus !== 'undefined') {
            $(focus).focus();
        }

    });

    // Sticky Navbar
    function navbarFixed() {
        var windowTop  = $(window).scrollTop();
        var offsetTop  = $('body').offset().top + 50;

        if (windowTop > offsetTop) {
            $('.navbar-default').addClass('navbar-sticky');
        } else {
            $('.navbar-default').removeClass('navbar-sticky');
        }
    }

    if ($('.navbar-fixed-top').length) {
        adjust = 50;
        $(window).scroll(navbarFixed);
    }

    function stickyNav() {
        var windowTop  = $(window).scrollTop();
        var offsetFrom = $('.secondary-nav').data('sticky-nav-offset');
        var offsetTop  = $(offsetFrom).offset().top;

        if (windowTop > offsetTop) {
            $('.secondary-nav').addClass('secondary-nav-sticky');
        } else {
            $('.secondary-nav').removeClass('secondary-nav-sticky');
        }
    }

    var hamburger = $('.hamburger');

    $(hamburger).click(function(event) {
        $(this).toggleClass('is-active');
    });

    if ($('.secondary-nav').length) {
        adjust = 50;
        $(window).scroll(stickyNav);
    }

    // Collapse Panels - FAQs
    $('.panel-heading').click(function(event) {

        event.preventDefault();

        $(this).toggleClass('panel-open');
        $('.panel-open').not(this).removeClass('panel-open');
    });

    // Enable Popovers
    if (jQuery.fn.popover) {
        $('[data-toggle="popover"]').popover();
    }

    // Policy Modal
    // Find the headings to add class
    $('#policy-modal, .modal-policy').find('h2').addClass('section-header');
    // Find the tables to add some styling classes
    $('#policy-modal, .modal-policy').find('table').addClass('table, table-bordered');
    // Hide the sections content
    $('.section-header').nextAll().not('h1').not('.section-header').hide();
    // Click function
    $('.section-header').each(function(index) {

        $(this).on('click', function(event) {
            $(this).nextUntil('.section-header').toggle();
            $(this).toggleClass('section-open');
        });

    });
});

$(function(){

    $.fn.extend({
        toggleText: function(a, b){
            return this.text(this.text() == b ? a : b);
        }
    });

    // SCROLL TO - Wahoooooo
    // Add anchor - data-scroll-to-anchor
    // To focus   - data-scroll-to-focus
    var nav = '';

    $('.scroll-to').click(function(e) {

        e.preventDefault();

        var anchor = $(this).data('scroll-to-anchor');
        var focus  = $(this).data('scroll-to-focus');

        $('html, body').animate({
            scrollTop: $(anchor).offset().top - nav
        }, 1500);

        // Unfocus the button!
        $(this).blur();

        if (typeof focus !== 'undefined') {
            $(focus).focus();
        }

    });

    // FIXED NAV & STICKY NAV
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
        var nav = 50;
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

    if ($('.secondary-nav').length) {
        nav = 50;
        $(window).scroll(stickyNav);
    }

    // IMAGE SRC SWAP
    $('.image-swap').each(function() {
        $(this).on('mouseover', function() {
            $(this).attr('src', $(this).data('hover-src'));
        }).on('mouseout', function() {
            $(this).attr('src', $(this).data('orig-src'));
        });
    });

    // ???
    $('#phone_phone').change(function() {
        $.get('/price/' + this.value + '/', function(data) {
            $('#policy-price').text('£' + data.price);
        });
    });

    // Collapse Panels - FAQs
    $('.panel-heading').click(function(event) {

        event.preventDefault();

        $(this).toggleClass('panel-open');
        $('.panel-open').not(this).removeClass('panel-open');
    });

    // Enable Popovers
    $('[data-toggle="popover"]').popover();

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




    // $('.section-header').nextAll().not('h1').not('h2').hide();
    // $('.section-header').each(function(index, el) {
    //     $(this).click(function(event) {
    //         $(this).nextUntil().toggle();
    //     });
    // });

    // $('.section-header').nextAll().not('h1').not('h2').hide();
    // $('.section-header').click(function(e) {
    //     $(this).nextUntil('section-header').toggle();
    //     $(this).toggleClass('section-open');
    // });

});

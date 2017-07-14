$(function(){

    $.fn.extend({
        toggleText: function(a, b){
            return this.text(this.text() == b ? a : b);
        }
    });

    // SCROLL TO - Wahoooooo
    // Add anchor - data-scroll-to-anchor
    // To focus   - data-scroll-to-focus
    $('.scroll-to').click(function(e) {

        e.preventDefault();

        var anchor = $(this).data('scroll-to-anchor');
        var focus  = $(this).data('scroll-to-focus');

        $('html, body').animate({
            scrollTop: $(anchor).offset().top
        }, 1500);

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
    $('#policy-modal').on('show.bs.modal', function (event) {

        var modal = $(this);
        var h1    = $(this).find('h1');
        var h2    = $(this).find('h2');

        // modal.find('h1').hide();

        modal.find(h2).nextAll().not(h1).not(h2).hide();

        modal.find('table').addClass('table, table-bordered');

        h2.click(function() {

            $(this).nextUntil(h2).slideToggle();
            $(this).toggleClass('section-open');
        });


    });

});

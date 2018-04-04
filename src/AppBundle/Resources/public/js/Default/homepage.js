// homepage.js
$(function(){

    // Enhance the focus on the search box when input focused
    $('.search-phone').each(function(index, el) {
        var container = $(this).parents('.search-phone-form');
        $(this).on('focus', function(){

            // Add focus style
            $(container).addClass('search-phone-form--focus');

            // If mobile slide up to make more room
            // if (sosure.globals.device_category == 'Mobile') {
            //     $('html,body').animate({scrollTop: $(this).offset().top - 100}, 800);
            // }
        });

        // Remove class on blur
        $(this).on('blur', function(){
            $(container).removeClass('search-phone-form--focus');
        });
    });

    // Sticky Search - Homepage only
    function stickySearch() {
        var windowTop  = $(window).scrollTop();
        var searchBox  = $('#select-phone-data');
        var offsetTop  = $('.homepage--hero').height();
        var hitPoint   = $('.homepage--instant-quote').offset().top;

        if (windowTop > offsetTop && windowTop < hitPoint) {
            $(searchBox).addClass('search-phone-form--sticky').addClass('animated bounceInDown');
        } else {
            $(searchBox).removeClass('search-phone-form--sticky').removeClass('animated bounceInDown');
        }
    }

    if ($('#select-phone-data').data('sticky-search') == true ) {
        $(window).scroll(stickySearch);
    }


});

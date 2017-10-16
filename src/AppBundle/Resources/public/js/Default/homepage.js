// homepage.js
$(function(){

    // Sticky Search
    // function stickySearch() {
    //     var windowTop  = $(window).scrollTop();
    //     var searchBox  = $('#select-phone-data-1');
    //     var offsetTop  = $('.homepage--hero').height();
    //     var hitPoint   = $('.homepage--instant-quote').offset().top;

    //     if (windowTop > offsetTop && windowTop < hitPoint) {
    //         $(searchBox).addClass('search-phone-form--sticky');
    //     } else {
    //         $(searchBox).removeClass('search-phone-form--sticky');
    //     }
    // }

    // if ($('#search-phone-form-homepage-1').length) {
    //     $(window).scroll(stickySearch);
    // }

    // Enhance the focus on the search box when input focused
    $('.search-phone').each(function(index, el) {
        var container = $(this).parents('.search-phone-form');
        $(this).on('focus', function(){
            $(container).addClass('search-phone-form--focus');
        });
        $(this).on('blur', function(){
            $(container).removeClass('search-phone-form--focus');
        });
    });

});

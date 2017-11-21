// homepage.js
$(function(){

    // Get the current device
    var device = $('#ss-root').data('device-category');

    // Enhance the focus on the search box when input focused
    $('.search-phone').each(function(index, el) {
        var container = $(this).parents('.search-phone-form');
        $(this).on('focus', function(){
            // Add focus style
            $(container).addClass('search-phone-form--focus');

            // If mobile slide up to make more room
            if (device == 'Mobile') {
                $('html,body').animate({scrollTop: $(this).offset().top - 100}, 800);
            }
        });

        // Remove class on blur
        $(this).on('blur', function(){
            $(container).removeClass('search-phone-form--focus');
        });
    });

});

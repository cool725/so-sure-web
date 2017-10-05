// homepage.js
$(function(){

    // Defer images for faster page speed

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

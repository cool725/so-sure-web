$(function(){

    // Animate gifs for cashback
    $('.img-animation').each(function(index, el) {

        var origSrc = $(this).prop('src');
        var swapGif = $(this).data('src');
        var text    = $(this).siblings('p');

        $(this).hover(function() {
            $(this).prop('src', swapGif);
            $(text).animate({opacity: 1});
        }, function() {
            $(this).prop('src', origSrc);
             $(text).animate({opacity: 0});
        });
    });

});

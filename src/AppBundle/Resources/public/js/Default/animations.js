$(function(){

    // Animate gifs for cashback
    $('.img-animation').each(function(index, el) {

        var origSrc = $(this).prop('src');
        var swapGif = $(this).data('src');

        $(this).hover(function() {
            $(this).prop('src', swapGif);
        }, function() {
            $(this).prop('src', origSrc);
        });
    });

});

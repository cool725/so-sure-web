$(function(){

    // IMAGE SRC SWAP
    $('.image-swap').each(function() {
        $(this).on('mouseover', function() {
            $(this).attr('src', $(this).data('hover-src'));
        }).on('mouseout', function() {
            $(this).attr('src', $(this).data('orig-src'));
        });
    });

});

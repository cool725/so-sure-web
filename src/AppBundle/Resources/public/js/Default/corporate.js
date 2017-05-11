// Coroporate JS
$(function(){

    // SCROLL TOO - Wahoooooo
    //TODO - Change all to use this function
    $('.scroll-too').click(function(e) {

        e.preventDefault();

        var anchor = $(this).data('scroll-to-anchor');

        $('html, body').animate({
            scrollTop: $(anchor).offset().top
        }, 1500);

    });

});

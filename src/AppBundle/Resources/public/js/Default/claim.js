$(function(){
    if ($('.has-error').length) {
        $('html,body').animate({
           scrollTop: $("#claim-form-container").offset().top
        });
    }
});

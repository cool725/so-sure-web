$(function(){

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 30,
        stagePadding: 100,
        items: 1,
        loop: true
    });

    $('.item').trigger('initialized.owl.carousel').show();


    // $('.form-control').on('change', function() {
    //     $(this).parent().removeClass('has-error');
    //     $(this).parent().find('.with-errors').empty();
    // });

});

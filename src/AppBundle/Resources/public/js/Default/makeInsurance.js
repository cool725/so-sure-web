// Make Insurance JS
$(function(){

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 40,
        stagePadding: 100,
        items: 1,
    });

    $('.item').trigger('initialized.owl.carousel').show();

});

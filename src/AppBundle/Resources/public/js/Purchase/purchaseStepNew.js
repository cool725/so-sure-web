$(function(){

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 30,
        stagePadding: 70,
        items: 2,
        loop: true
    });

    $('.item').trigger('initialized.owl.carousel').show();
});

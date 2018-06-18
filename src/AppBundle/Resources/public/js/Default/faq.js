// faqs.js
$(function(){

    $('.faqs').scrollspy({
        target: '#faqs__nav',
        offset: 100,
    });

    $('#faqs__nav li a[href^="#"]').on('click', function(e) {

       // prevent default anchor click behavior
       e.preventDefault();

       // store hash
       var hash = this.hash;

       // animate
       $('html, body').animate({
           scrollTop: $(hash).offset().top
         }, 300, function(){

           // when done, add hash to url
           // (default click behaviour)
           window.location.hash = hash;
         });

    });

    // Init BS affix
    $('#faqs__nav').affix({
        offset: {
            top: 0,
            bottom: function () {
                return (this.bottom = $('footer').outerHeight(true) + 200)
            }
        }
    });

});

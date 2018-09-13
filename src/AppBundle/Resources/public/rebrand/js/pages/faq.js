// faq.js

require('../../sass/pages/faq.scss');

// Require BS component(s)
require('bootstrap/js/dist/scrollspy');
// require('bootstrap/js/dist/dropdown');

// Require components
require('../common/fixedWidth.js');

$(function() {

    // Init scrollspy
    $('body').scrollspy({
        target: '#faq-nav',
        offset: 10,
    });

    // Add smooth scroll and active class on click
    $('#faq-nav a[href^="#"]').on('click', function(e) {

        // prevent default anchor click behavior
        e.preventDefault();

        $('#faq-nav a').removeClass('active');
        $(this).parent().addClass('active');

        // store hash
        let hash = this.hash;

        // animate
        $('html, body').animate({
            scrollTop: $(hash).offset().top - 55
        }, 300, function(){

            // when done, add hash to url
            // (default click behaviour)
            window.location.hash = hash;
        });

    });

    $('#back-to-top-faq').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: 0
        }, 300);
    });


});

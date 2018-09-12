// faq.js

require('../../sass/pages/faq.scss');

// Require BS component(s)
require('bootstrap/js/dist/scrollspy');
// require('bootstrap/js/dist/dropdown');

// Require components
require('../common/fixedWidth.js');

$(function() {

    // Init scrollspy
    $('.faq').scrollspy({
        target: '#faq-nav',
        offset: 10,
    });

    // Add smooth scroll and active class on click
    $('#faq-nav li a[href^="#"]').on('click', function(e) {

        // prevent default anchor click behavior
        e.preventDefault();

        $('#faq-nav li').removeClass('active');
        $(this).parent().addClass('active');

        // store hash
        let hash = this.hash;

        // animate
        $('html, body').animate({
            scrollTop: $(hash).offset().top
        }, 300, function(){

            // when done, add hash to url
            // (default click behaviour)
            window.location.hash = hash;
        });

    });

    $('#back-to-top-faq').on('click', function(e) {
        event.preventDefault();

        $('html,body').animate({
            scrollTop: 0
        }, 300);
    });


});

// faq.js

require('../../sass/pages/content-pages.scss');

// Require BS component(s)
require('bootstrap/js/dist/scrollspy');
// require('bootstrap/js/dist/dropdown');

// Require components
require('../common/fixedWidth.js');

$(function() {

    // Init scrollspy
    $('body').scrollspy({
        target: '#faq-nav',
        offset: 200
    });

    $('#faq-nav a').on('click', function(e) {
        if (this.hash != '') {
            e.preventDefault();

            let hash = this.hash;

            $('html, body').animate({
                scrollTop: $(hash).offset().top
                }, 800, function(){

                window.location.hash = hash;
            });
        }
    });

    $('#back-to-top-faq').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: 0
        }, 300);
    });


});

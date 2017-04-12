// Sticky Banner - For quotes TODO make modular

var stickyBanner  = $('.banner-sticky');
var stickyTrigger = $(stickyBanner).data('sticky-trigger');
var stickyStatic  = $(stickyBanner).data('sticky-static');    
var triggerHeight = $('#'+stickyTrigger).height();
var triggerOffset = $(stickyBanner).data('sticky-offset');

$(document).scroll(function() {

    if (typeof stickyTrigger !== 'undefined' && stickyTrigger.length) {

        var triggerBottom = $('#'+stickyTrigger).offset().top - triggerHeight + triggerOffset;

        if ($(window).scrollTop() > triggerBottom + triggerHeight) {

            $('.banner-sticky').fadeIn();                

        } else {

            $('.banner-sticky').fadeOut();                

        }
        
    }

});  

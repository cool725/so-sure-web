// Sticky Banner - For quotes TODO make modular

var stickyBanner  = $('.banner-sticky');
var stickyTrigger = $(stickyBanner).data('sticky-trigger');
var stickyStatic  = $(stickyBanner).data('sticky-static');    
var triggerHeight = $('#'+stickyTrigger).height();
var triggerOffset = $(stickyBanner).data('sticky-offset');

$(document).scroll(function() {

    if (stickyTrigger.length) {

        var triggerBottom = $('#'+stickyTrigger).offset().top - triggerHeight + triggerOffset;

        if ($(window).scrollTop() > triggerBottom + triggerHeight) {

            $('.banner-sticky').fadeIn();                

        } else {

            $('.banner-sticky').fadeOut();                

        }
        
    }

});  

// Policy Modal
$('#policy-modal').on('show.bs.modal', function (event) {

    var modal = $(this);
    var h1    = $(this).find('h1');
    var h2    = $(this).find('h2');        

    // modal.find('h1').hide();

    modal.find(h2).nextAll().not(h1).not(h2).hide();

    modal.find('table').addClass('table, table-bordered');

    h2.click(function() {

        $(this).nextUntil(h2).slideToggle();
        $(this).toggleClass('section-open');
    });


});
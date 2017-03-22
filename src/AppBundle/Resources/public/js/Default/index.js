$(function(){
    if(window.location.href.indexOf('?quote=1') != -1) {
        $('#quoteModel').modal('show');
        sosuretrack('Get A Quote Link', function() {
        });
    }

    $.fn.extend({
        toggleText: function(a, b){
            return this.text(this.text() == b ? a : b);
        }
    });    

    $('#phone_phone').change(function() {
        $.get('/price/' + this.value + '/', function(data) {
            $('#policy-price').text('£' + data.price);
        });
    });

    $("#how-it-works-btn").click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#how-it-works").offset().top - 100
        }, 1500);
    });


    $("#so-sure-compared-btn").click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#so-sure-compared").offset().top - 200
        }, 1500);
    });


    $("#get-started").click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#download-now").offset().top
        }, 1500);
    });

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

});
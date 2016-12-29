    $('#phone_phone').change(function() {
        $.get('/price/' + this.value + '/', function(data) {
            $('#policy-price').text('£' + data.price);
        });
    });

    $("#get-quote-btn").click(function() {
        $('html, body').animate({
            scrollTop: $("#get-quote").offset().top
        }, 1500);
    });

    $("#learn-more-btn").click(function() {
        $('html, body').animate({
            scrollTop: $("#learn-more").offset().top
        }, 1500);
    });

    $("#get-started").click(function() {
        $('html, body').animate({
            scrollTop: $("#download-now").offset().top
        }, 1500);
    });


    // Detect browser and show/hide elements using class

    var userAgent = navigator.userAgent || navigator.vendor || window.opera;

    if (/iPad|iPhone|iPod/.test(userAgent) && !window.MSStream) {

        $('.apple-hide').css('display', 'inline');
        // console.log('Apple');
    }

    if (/android/i.test(userAgent)) {
        
        $('.android-hide').css('display', 'inline');
        // console.log('Android');

    }        

    // Sticky Banner - For quotes TODO make modular

    var quoteBox = $('#quote');
    var footer   = $('.footer');

    $(window).scroll(function(event) {

        if (quoteBox.length) {

            var quoteBoxBottom = quoteBox.offset().top;

            if ($(window).scrollTop() > quoteBoxBottom + 200) {

                $('#quote-banner').fadeIn();                

            } else {

                $('#quote-banner').fadeOut();                

            }
            
        }   

    });    

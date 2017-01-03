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

    $("#how-it-works-btn").click(function() {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#how-it-works").offset().top - 100
        }, 1500);
    });

    $("#get-started").click(function() {
        $('html, body').animate({
            scrollTop: $("#download-now").offset().top
        }, 1500);
    });

    // Sticky Banner - For quotes TODO make modular

    var quoteBox = $('#quote');
    var footer   = $('.footer');

    $(window).scroll(function(event) {

        if (quoteBox.length) {

            var quoteBoxBottom = quoteBox.offset().top;

            if ($(window).scrollTop() > quoteBoxBottom + 400) {

                $('#quote-banner').fadeIn();                

            } else {

                $('#quote-banner').fadeOut();                

            }
            
        }   

    });    

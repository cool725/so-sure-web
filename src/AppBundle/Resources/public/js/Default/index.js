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

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
            scrollTop: $("#benefits").offset().top
        }, 1500);
    });

    $("#connections-info-icon").click(function() {
        $('html, body').animate({
            scrollTop: $("#connections").offset().top
        }, 1500);
    });

    $("#quote-scroll-indicator").click(function() {
        $('html, body').animate({
            scrollTop: $("#connections").offset().top
        }, 1500);
    });

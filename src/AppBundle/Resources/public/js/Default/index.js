    $('#phone_phone').change(function() {
       $.get('/price/' + this.value + '/', function(data) {
        $('#policy-price').text('£' + data.price);
       });
    });

    $("#scroll-indicator").click(function() {
        $('html, body').animate({
            scrollTop: $("#learn-more").offset().top
        }, 1500);
    });

    $("#connections-info-icon").click(function() {
        $('html, body').animate({
            scrollTop: $("#connections").offset().top
        }, 1500);
    });

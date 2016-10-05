    $('#phone_phone').change(function() {
       $.get('/price/' + this.value + '/', function(data) {
        $('#policy-price').text('£' + data.price);
       });
    });

    $("#get-started").click(function() {
        $('html, body').animate({
            scrollTop: $("#download-now").offset().top
        }, 1500);
    });

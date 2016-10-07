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

    $("#get-started").click(function() {
        $('html, body').animate({
            scrollTop: $("#download-now").offset().top
        }, 1500);
    });

    var iframe = document.getElementById('exp-vid');
    var player = $f(iframe);

    $('.modal').on('hidden.bs.modal', function () {
      player.api('pause');
    })

    $('.modal').on('shown.bs.modal', function () {
      player.api('play');
    })

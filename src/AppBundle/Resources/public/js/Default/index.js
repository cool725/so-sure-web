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

    var iframe = document.querySelector('iframe');
    var player = new Vimeo.Player(iframe);

    $('.modal').on('hidden.bs.modal', function() {
        player.pause();
    })

    $('.modal').on('shown.bs.modal', function() {
        player.play();
    })

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

    var iframe = document.querySelector('#exp-vid');
    var player = new Vimeo.Player(iframe);

    $('.modal-video').on('hidden.bs.modal', function() {
        player.pause();
    })

    $('.modal-video').on('shown.bs.modal', function() {
        player.play();
    })

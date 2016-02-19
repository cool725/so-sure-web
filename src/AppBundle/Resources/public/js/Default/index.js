    $('#phone_phone').change(function() {
       $.get('/price/' + this.value + '/', function(data) {
        $('#policy-price').text('£' + data.price);
       });
    });

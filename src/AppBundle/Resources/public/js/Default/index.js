    $('#phone_phone').change(function() {
       $.get('/purchase/price/' + this.value + '/', function(data) {
        $('#policy-price').text('£' + data.price);
       });
    });

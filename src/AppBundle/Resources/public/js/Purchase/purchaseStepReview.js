$(function(){
    $('#webpay-confirm').on('change', function(){
       if ($('#webpay-confirm').is(':checked')) {
        $('#webpay-form').attr('action', $('#webpay-form').data('action'));
       } else {
        $('#webpay-form').attr('action', '');
       }
    });
});

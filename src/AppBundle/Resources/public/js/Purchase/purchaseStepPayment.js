// payment.js
$(function(){

    // Payments page
    $('.sort-code').mask('00-00-00');

    $('.web-pay-submit').on('click', function() {
        if ($.trim($('#Reference').val()).length > 0) {
            // Show loading overlay
            $('.so-sure-loading').show();
            $('#webpay-form').submit();
        }
    });
});

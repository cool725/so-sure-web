$(function(){
    $('#webpay-confirm').on('change', function(){
       if ($('#webpay-confirm').is(':checked')) {
        $('#webpay-form').attr('action', $('#webpay-form').data('action'));
       } else {
        $('#webpay-form').attr('action', '');
       }
    });
});

$('#policy-modal').on('show.bs.modal', function (event) {
    sosuretrack('View Full Policy');
});

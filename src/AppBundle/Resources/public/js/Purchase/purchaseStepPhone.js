$(function(){
    $('input:radio').click(function(){
        $(this).parent().parent().addClass('radio-selected')
               .siblings().removeClass('radio-selected');
    });

    $('#policy-modal').on('show.bs.modal', function (event) {
        sosure.track.byName('View Full Policy');
    });
    if ($('#webpay-form').attr('action')) {
        $('#webpay-form').submit();
    }
});

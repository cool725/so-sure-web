$(function(){
	$('input:radio').click(function(){
		$(this).parent().parent().addClass('radio-selected')
		       .siblings().removeClass('radio-selected');
	});

    $('#policy-modal').on('show.bs.modal', function (event) {
        sosuretrack('View Full Policy');
    });
    alert($('#webpay-form').attr('action'));
    if ($('#webpay-form').attr('action')) {
        $('#webpay-form').submit();
    }
});

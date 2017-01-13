$(function(){

    $('.form-control').on('keyup', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').hide();
    });

	$('input:radio').click(function(){

		$(this).parent().parent().addClass('radio-selected')
		       .siblings().removeClass('radio-selected');

	});

});

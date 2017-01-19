$(function(){

    $('.form-control').on('change', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').empty();
    });
    
	$('input:radio').click(function(){

		$(this).parent().parent().addClass('radio-selected')
		       .siblings().removeClass('radio-selected');

	});

});

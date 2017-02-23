$(function(){
	$('input:radio').click(function(){

		$(this).parent().parent().addClass('radio-selected')
		       .siblings().removeClass('radio-selected');

	});

});

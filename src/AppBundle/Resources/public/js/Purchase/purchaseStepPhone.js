$(function(){
    $('.form-control').on('keyup', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').hide();
    });
});

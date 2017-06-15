$(function(){
    $('.form-control').on('change', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').empty();
    });
});

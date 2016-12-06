$(function(){
    $('#birthday').datetimepicker({
        format: "DD/MM/YYYY",
        allowInputToggle: true,
        showTodayButton: false,
        useCurrent: false 
    });

    $('.form-control').on('keyup', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').hide();
    });
});

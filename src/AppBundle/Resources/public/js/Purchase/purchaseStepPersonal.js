$(function(){

    $('#purchase_form_birthday').daterangepicker({
        parentEl: '#birthday',
        singleDatePicker: true,
        showDropdowns: true,
        drops: "up",
        locale: {
            format: 'DD/MM/YYYY'
        }            
    });

    $('.form-control').on('keyup', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').hide();
    });
});

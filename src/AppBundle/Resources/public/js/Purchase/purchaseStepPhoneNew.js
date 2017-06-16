$(function(){

    // Payment buttons action radio buttons
    $('.payment--btn').click(function(event) {

        $(this).toggleClass('payment--btn-selected')
        .siblings()
        .removeClass('payment--btn-selected');

        var radio = $(this).data('target');

        $(radio).prop('checked', true);

    });

    // Validate step
    $('#step--validate').click(function(e) {

        e.preventDefault();

        var form = $('.validate-form');

        form.validate({
            debug: true,
            rules: {
                "purchase_form[imei]" : {
                    required: true,
                    digits: true,
                    minlength: 15,
                    maxlength: 17,
                    imei: true
                }
            },
            messages: {
                "purchase_form[imei]" : {
                    required: 'Enter a valid 15 or 17 digit IMEI Number',
                    digits: 'Only digits are valid for an IMEI Number',
                    imei: 'IMEI Number is not valid'
                }
            },

            submitHandler: function(form) {
                form.submit();
            },

            invalidHandler: function(event, validator) {
                $('.payment--step h4 small').addClass('error');
            }
        });

        if (form.valid() == true){

            console.log('Valid');

            // TODO Add IMEI check here?

            $('#reviewModal').modal();
        }
    });

});

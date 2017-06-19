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
            onfocusout: function(element) {
                this.element(element);
                // console.log('onfocusout fired');
            },
            validClass: 'has-success',
            rules: {
                "purchase_form[imei]" : {
                    required: true,
                    digits: true,
                    minlength: 15,
                    maxlength: 17,
                    imei: true
                    // remote: We can use this option to lookup imei
                },
                "purchase_form[amount]" : {
                    required: true
                },
                "purchase_form[serialNumber]" : {
                    required: true,
                    digits: true
                }
            },
            messages: {
                "purchase_form[imei]" : {
                    required: 'Enter a valid 15 or 17 digit IMEI Number',
                    digits: 'Only digits are valid for an IMEI Number',
                    imei: 'IMEI Number is not valid'
                },
                "purchase_form[serialNumber]" : {
                    required:'Enter a valid serial number',
                    digits: 'Only digits are valid for a Serial Number'
                }
            },

            errorPlacement: function(error, element) {
                if (element.attr('name') == 'purchase_form[amount]') {
                    $('.payment--step h4 small').addClass('error');
                } else {
                    error.insertAfter(element);
                }
            },

            submitHandler: function(form) {
                form.submit();
            },

        });

        if (form.valid() == true){

            // TODO Add IMEI check here?

            $('#reviewModal').modal();
        }
    });

});

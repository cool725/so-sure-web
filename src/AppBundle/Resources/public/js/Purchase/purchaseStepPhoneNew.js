$(function(){

    // Payment buttons action radio buttons
    $('.payment--btn').click(function(event) {

        $(this).toggleClass('payment--btn-selected')
        .siblings()
        .removeClass('payment--btn-selected');

        var value = $(this).data('value');
        $("input[name=purchase_form[amount]][value=" + value + "]").prop('checked', true);
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
                    minlength: 15,
                    creditcard: true,
                    normalizer: function(el) {

                        var creditcard = el.value;

                        console.log('Original: ' + creditcard);

                        var creditcard = creditcard.replace(/\D/g,'').substring(0, 15);

                        console.log('Modified: ' + creditcard);

                        return creditcard;
                    }
                },
                "purchase_form[amount]" : {
                    required: true
                },
                "purchase_form[serialNumber]" : {
                    required: true,
                    alphanumeric: true
                }
            },
            messages: {
                "purchase_form[imei]" : {
                    required: 'Please enter a valid IMEI Number',
                    creditcard: 'Please enter a valid IMEI Number CC'

                },
                "purchase_form[serialNumber]" : {
                    required: 'Please enter a valid serial number',
                    alphanumeric: 'Please enter a valid serial number',
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

    if ($.trim($('#Reference').val()).length > 0) {
        $('#webpay-form').submit();
    }
});

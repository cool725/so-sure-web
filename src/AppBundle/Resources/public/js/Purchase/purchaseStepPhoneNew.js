var sosure = sosure || {};

sosure.purchaseStepPhone = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
        self.form = $('.validate-form');
        self.addValidation();
    }

    self.valid_credit_card = function(value) {
        // accept only digits, dashes or spaces
        if (/[^0-9-\s]+/.test(value)) return false;

        // The Luhn Algorithm. It's so pretty.
        var nCheck = 0, nDigit = 0, bEven = false;
        value = value.replace(/\D/g, "");

        for (var n = value.length - 1; n >= 0; n--) {
            var cDigit = value.charAt(n),
                nDigit = parseInt(cDigit, 10);

            if (bEven) {
                if ((nDigit *= 2) > 9) nDigit -= 9;
            }

            nCheck += nDigit;
            bEven = !bEven;
        }

        return (nCheck % 10) == 0;
    }

    self.addValidation = function() {
        $.validator.addMethod(
            "imei", 
            function(value, element) {
                var imei = value; //$('#purchase_form_imei').val();
                imei = imei.replace('/', '');
                imei = imei.replace('-', '');
                imei = imei.replace(' ', '');
                imei = imei.substring(0, 15);
                var valid = sosure.purchaseStepPhone.valid_credit_card(imei);
                return valid;
            }
        );
        self.form.validate({
            debug: false,
            onfocusout: function(element) {
                this.element(element);
                // console.log('onfocusout fired');
            },
            validClass: 'has-success',
            rules: {
                "purchase_form[imei]" : {
                    required: true,
                    minlength: 15,
                    imei: true
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
                    minlength: 'Please enter a valid IMEI Number',
                    imei: 'Please enter a valid IMEI Number'

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
            }
        });
    }

    self.step_continue = function() {
        if (self.form.valid() == true){
            $('#reviewModal').modal();
        }
    }

    return self;
})();

$(function(){
    sosure.purchaseStepPhone.init();
});

$(function(){

    // Payment buttons action radio buttons
    $('.payment--btn').click(function(event) {

        $(this).toggleClass('payment--btn-selected')
        .siblings()
        .removeClass('payment--btn-selected');

        var value = $(this).data('value');
        $('input[name="purchase_form[amount]"][value="' + value + '"]').prop('checked', true);
    });

    // Validate step
    $('#step--validate').click(function(e) {
        e.preventDefault();
        sosure.purchaseStepPhone.step_continue();
    });

    if ($.trim($('#Reference').val()).length > 0) {
        $('#webpay-form').submit();
    }
});

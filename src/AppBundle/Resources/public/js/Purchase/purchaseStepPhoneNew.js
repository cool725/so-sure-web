var sosure = sosure || {};

sosure.purchaseStepPhone = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
        self.form = $('.validate-form');
        if (self.form.data('client-validation')) {
            self.addValidation();
        }
    };

    self.valid_credit_card = function(value) {
        // accept only digits, dashes or spaces
        if (/[^0-9-\s]+/.test(value)) return false;

        // The Luhn Algorithm. It's so pretty.
        var nCheck = 0, bEven = false;
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

        return (nCheck % 10) === 0;
    };

    self.addValidation = function() {
        $.validator.addMethod(
            "imei",
            function(value, element) {
                var imei = value;
                imei = imei.replace('/', '');
                imei = imei.replace('-', '');
                imei = imei.replace(' ', '');
                imei = imei.substring(0, 15);
                return sosure.purchaseStepPhone.valid_credit_card(imei);
            }
        );
        self.form.validate({
            debug: false,
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
                    alphanumeric: 'Please enter a valid serial number'
                }
            },

            errorPlacement: function(error, element) {
                if (element.attr('name') === "purchase_form[amount]") {
                    $('.payment-options h4').addClass('error');
                } else {
                    error.insertAfter(element);
                }
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    };

    return self;
})();

$(function(){
    sosure.purchaseStepPhone.init();
});

$(function(){

    // Payment buttons action radio buttons
    $('.payment-options--btn').click(function() {
        // Toggle Class on Btns
        $('.payment-options--btn').removeClass('payment-options--btn-selected animated pulse');
        $(this).addClass('payment-options--btn-selected animated pulse');

        // Grab data from btn value to input
        var value = $(this).data('value');
        // var help  = $(this).data('help-block');

        // Select the radio
        $('input[name="purchase_form[amount]"][value="' + value + '"]').prop('checked', true);

        // Modify the help text accordingly
        // $('.payment-options--info').text(help);
    });

    $('input[name="purchase_form[amount]').change(function() {
        //console.log('Yes');
    });

    if ($.trim($('#Reference').val()).length > 0) {
        // Show loading overlay
        $('.so-sure-loading').show();
        $('#webpay-form').submit();
    }

    $('#imei-screenshot').click(function(e) {
        e.preventDefault();
        sosure.track.byName('Clicked Upload Imei');
        Intercom('trackEvent', 'clicked upload imei');
        Intercom('showNewMessage', $(this).data('intercom-msg'));
    });

    // Trim as you type
    $('.imei').on('keyup paste', function() {
        var simei  = $(this).val();

        if (simei.indexOf('/') > 1) {
            var newtxt = simei.replace('/', '');
            $(this).val(newtxt);
            $('.samsung-imei').show();
        }

        if ($(this).valid()) {
            $('.samsung-imei').hide();
        }
    });
});

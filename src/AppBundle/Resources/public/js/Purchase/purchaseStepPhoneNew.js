var sosure = sosure || {};

sosure.purchaseStepPhone = (function() {
    var self = {};
    self.form = null;
    self.isIE = null;

    self.init = function() {
        self.form = $('.validate-form');
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
    };

    self.addValidation = function() {
        self.form.validate({
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            // onfocusout: false,
            onkeyup: false,
            onclick: false,
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
                    $('.payment-options__title').addClass('error');
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

    // TODO: Move to component
    $('.radio-btn').on('click', function(e) {
        e.preventDefault();

        $('.radio-btn').removeClass('radio-btn-active');
        $(this).addClass('radio-btn-active');

        // Set the value for the form element
        var val = $(this).data('value');
        $('input[name="purchase_form[amount]"][value="' + val + '"]').prop('checked', true);

        // Adjust the price in the copy
        var premium = $(this).data('premium-type');
        var price = $('#purchase_price');
        price.html('&pound;' + val + ' a ' + premium);
    });

    if ($.trim($('#Reference').val()).length > 0) {
        // Show loading overlay
        // TODO: New loader
        $('.so-sure-loading').show();
        $('#webpay-form').submit();
    }

    // Trim as you type
    // TODO: Rework as it's affecting validation - possible fix for now
    var imei  = $('.imei'),
        phone = imei.data('make');

    if (phone == 'Samsung') {
        imei.on('blur', function() {
            var simei  = $(this).val();

            if (simei.indexOf('/') > 1) {
                var newtxt = simei.replace('/', '');
                $(this).val(newtxt);
            }

            if ($(this).valid()) {
                $('.samsung-imei').hide();
            }
        });
    }
});

var sosure = sosure || {};

sosure.purchaseStepPhone = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
        self.form = $('.validate-form');
        if (self.form.data('client-validation')) {
            self.addValidation();
        }
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

    self.addValidation = function(value) {
        $.validator.addMethod(
            "imei",
            function(value, element) {
                var imei = value;
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
                // Show loading overlay
                $('.so-sure-loading').show();
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
    $('.imei').on('keyup paste', function(event) {
        var simei  = $(this).val();

        if (simei.indexOf('/') > 1) {
            var newtxt = simei.replace('/', '');;
            $(this).val(newtxt);
            $('.samsung-imei').show();
        }

        if ($(this).valid()) {
            $('.samsung-imei').hide();
        }
    });

    // Hide/Show policy doc
    $('.policy-doc-toggle').click(function(e) {
        // e.preventDefault();
        $('.modal-body__policy-doc').toggle();
    });

    $('#policy-modal, .modal-policy').on('hide.bs.modal', function (event) {
        $('.modal-body__policy-doc').hide();
    });

    $('#purchase_form_file').on('change', function() {
        $('#upload-file-info').html('<i class="fa fa-file"></i> <span id="upload-file-name"></span>');
        // Use cation - .name is original client name and can have script input
        $('#upload-file-name').text(this.files[0].name);
        $('#purchase_form_imei').rules("remove");
        $('#purchase_form_imei').removeAttr("required");
        $('#purchase_form_serialNumber').rules("remove");
        $('#purchase_form_serialNumber').removeAttr("required");
        $('#step--validate').click();
    });
});

var sosure = sosure || {};

sosure.renew = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
        self.formRenewCashback = $('.validate-form-renew-cashback');
        self.formCashback = $('.validate-form-cashback');
        self.sortCode();
        self.addValidationRenewCashback();
        self.addValidationCashback();
    }

    self.sortCode = function () {
        $('#renew_cashback_form_sortCode, #cashback_form_sortCode').mask('00-00-00');
    }

    self.addValidationRenewCashback = function () {
        self.formRenewCashback.validate({
            debug: false,
            onkeyup: false,
            validClass: 'has-success',
            rules: {
                "renew_cashback_form[accountName]" : {
                    required: true,
                    minlength: 2
                },
                "renew_cashback_form[sortCode]" : {
                    required: true,
                    minlength: 8,
                    maxlength: 8,
                },
                "renew_cashback_form[accountNumber]" : {
                    required: true,
                    digits: true,
                    minlength: 8,
                    maxlength: 8
                }
            },
            messages: {
                "renew_cashback_form[accountName]" : {
                    required: 'Please enter your full name as it appears on your bank account',
                    minlength: 'Please enter your full name as it appears on your bank account',
                },
                "renew_cashback_form[sortCode]" : {
                    required: 'Please enter your 6 digit sort code',
                    minlength: 'Please enter your 6 digit sort code',
                    maxlength: 'Please enter your 6 digit sort code',
                },
                "renew_cashback_form[accountNumber]" : {
                    required: 'Please enter your 8 digit account number',
                    digits: 'Please enter your 8 digit account number',
                    minlength: 'Please enter your 8 digit account number',
                    maxlength: 'Please enter your 8 digit account number',
                }
            },
            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    self.addValidationCashback = function () {
        self.formCashback.validate({
            debug: false,
            onkeyup: true,
            validClass: 'has-success',
            rules: {
                "cashback_form[accountName]" : {
                    required: true,
                    minlength: 2
                },
                "cashback_form[sortCode]" : {
                    required: true,
                    minlength: 8,
                    maxlength: 8,
                },
                "cashback_form[accountNumber]" : {
                    required: true,
                    digits: true,
                    minlength: 8,
                    maxlength: 8
                }
            },
            messages: {
                "cashback_form[accountName]" : {
                    required: 'Please enter your full name as it appears on your bank account',
                    minlength: 'Please enter your full name as it appears on your bank account',
                },
                "cashback_form[sortCode]" : {
                    required: 'Please enter your 6 digit sort code',
                    minlength: 'Please enter your 6 digit sort code',
                    maxlength: 'Please enter your 6 digit sort code',
                },
                "cashback_form[accountNumber]" : {
                    required: 'Please enter your 8 digit account number',
                    digits: 'Please enter your 8 digit account number',
                    minlength: 'Please enter your 8 digit account number',
                    maxlength: 'Please enter your 8 digit account number',
                }
            },
            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    self.clear_payment_buttons = function(element) {
        element
        .parent()
        .parent()
        .find('.payment--btn')
        .removeClass('payment--btn-selected');
        $('#renew_form_encodedAmount').val('');
        $('#renew_cashback_form_encodedAmount').val('');
    }

    self.use_cashback = function(use_cashback) {
        if (use_cashback) {
            $('#payment--reward-btns').hide();
            $('#payment--cashback-btns').show();
            $('#renew--continue-btn').hide();
            $('#renew--continue-cashback-btn').show();
        } else {
            $('#payment--reward-btns').show();
            $('#payment--cashback-btns').hide();
            $('#renew--continue-btn').show();
            $('#renew--continue-cashback-btn').hide();
        }
    }

    return self;
})();

$(function(){
    sosure.renew.init();
});

$(function(){

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 30,
        stagePadding: 70,
        items: 2,
        loop: true
    });

    $('.item').trigger('initialized.owl.carousel').show();

    $('#payment--cashback').click(function(event) {
        sosure.renew.clear_payment_buttons($(this));
        sosure.renew.use_cashback(true);
    });

    $('#payment--reward').click(function(event) {
        sosure.renew.clear_payment_buttons($(this));
        sosure.renew.use_cashback(false);
    });

    $('.payment--btn').click(function(event) {
        sosure.renew.clear_payment_buttons($(this));

        $(this).toggleClass('payment--btn-selected');

        var value = $(this).data('value');
        $('#renew_form_encodedAmount').val(value);
        $('#renew_cashback_form_encodedAmount').val(value);
    });

    // Hide/Show policy doc
    $('.policy-doc-toggle').click(function(e) {
        // e.preventDefault();
        $('.modal-body__policy-doc').toggle();
    });

    $('#policy-modal, .modal-policy').on('hide.bs.modal', function (event) {
        $('.modal-body__policy-doc').hide();
    });

    // Connections
    $('.select-all-connections').on('click',function(){
        if (this.checked) {
            $('.select-all-connections').not(this).prop('checked',true);
            $('.checkbox-connect').each(function() {
                this.checked = true;
                $(this).parent().parent('li').addClass('background-off-white');
            });
        } else {
            $('.select-all-connections').not(this).prop('checked',false);
            $('.checkbox-connect').each(function() {
                this.checked = false;
                $(this).parent().parent('li').removeClass('background-off-white');
            });
        }
    });

    $('.checkbox-connect').on('click',function(){
        $(this).parent().parent('li').toggleClass('background-off-white');
        if ($('.checkbox-connect:checked').length == $('.checkbox-connect').length) {
            $('.select-all-connections').prop('checked',true);
        } else {
            $('.select-all-connections').prop('checked',false);
        }
    });


});

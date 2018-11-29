// faq.js

require('../../sass/pages/portal.scss');

// Require BS component(s)
// require('bootstrap/js/dist/scrollspy');

// Require components
require('jquery-validation');
require('../../../js/Default/jqueryValidatorMethods.js');

const sosure = sosure || {};

sosure.affPortal = (function() {
    let self = {};
    self.form = null;
    self.isIE = null;

    self.init = () => {
        self.form = $('.validate-form');
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
    }

    self.addValidation = () => {
        self.form.validate({
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            // onclick: false,
            rules: {
                "lead_form[name]": {
                    required: true,
                    fullName: true
                },
                "lead_form[email]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "lead_form[phone]": {
                    required: true
                },
                "lead_form[terms]": {
                    required: true
                }
            },
            messages: {
                "lead_form[name]": {
                    required: 'Please enter your full name e.g "John Smith"',
                    fullName: 'Please enter your first and last name  e.g "John Smith"'
                },
                "lead_form[email]": {
                    required: 'Please enter a valid email address.'
                },
                "lead_form[phone]": {
                    required: 'Please select a device'
                },
                "lead_form[terms]": {
                    required: ''
                }
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    return self;
})();

$(function() {
    sosure.affPortal.init();

    let whosEntering = false,
        who = null,
        labelTxt = null;

    let staff = (labelTxt) => {
        $('.who').addClass('hideme');
        $('.info').removeClass('hideme').find('.staff').removeClass('hideme');
        $('#lead_form_terms_label').text(labelTxt);
    }

    let customer = (labelTxt) => {
        $('.who').addClass('hideme');
        $('.info').removeClass('hideme').find('.customer').removeClass('hideme');
        $('#lead_form_terms_label').text(labelTxt);
    }

    let back = () => {
        $('.info').addClass('hideme').find('.staff, .customer').addClass('hideme');
        $('.who').removeClass('hideme');
        $('#lead_form_submittedBy').val(0);
    }

    $('#lead_form_submittedBy').on('change', function(e) {
        who = $(this).val();
        staffTxt = $(this).attr('data-staff');
        customerTxt = $(this).attr('data-customer');

        if (who == 1) {
            whosEntering = true;
            labelTxt = customerTxt;
        } else if (who == 2) {
            whosEntering = true;
            labelTxt = staffTxt;
        } else {
            whosEntering = false;
        }
    });


    $('#begin_btn').on('click', function(e) {
        e.preventDefault();

        if (who == 1) {
            customer(labelTxt);
        }

        if (who == 2) {
            staff(labelTxt);
        }
    });

    $('#restart_btn').on('click', function(e) {
        e.preventDefault();

        back();
    });
});

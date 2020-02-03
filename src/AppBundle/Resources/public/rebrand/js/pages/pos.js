// faq.js

require('../../sass/pages/pos.scss');

// Require BS component(s)
// require('bootstrap/js/dist/scrollspy');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');

const sosure = sosure || {};

sosure.pos = (function() {
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
            debug: false,
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
                "lead_form[optin]": {
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
                "lead_form[optin]": {
                    required: 'Please choose if you wish to opt in to marketing emails'
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
    sosure.pos.init();

    let whosEntering = false,
        who = null;

    let staff = () => {
        $('.who').addClass('hideme');
        $('.info').removeClass('hideme').find('.staff').removeClass('hideme');
        $('#lead_form_state').val('staff');
        //console.log($('#lead_form_state').val());
    }

    let customer = () => {
        $('.who').addClass('hideme');
        $('.info').removeClass('hideme').find('.customer').removeClass('hideme');
        $('#lead_form_state').val('customer');
        //console.log($('#lead_form_state').val());
    }

    let back = () => {
        $('.info').addClass('hideme').find('.staff, .customer').addClass('hideme');
        $('.who').removeClass('hideme');
        $('#lead_form_submittedBy').prop('selectedIndex', 0);
        $('#lead_form_state').val(null);
        //console.log($('#lead_form_state').val());
    }

    let state = $('#lead_form_state').val();
    //console.log($('#lead_form_state').val());
    if (state == 'customer') {
        customer();
    } else if (state == 'staff') {
        staff();
    }

    $('#lead_form_submittedBy').on('change', function(e) {
        who = $(this).val();
        staffTxt = $(this).attr('data-staff');
        customerTxt = $(this).attr('data-customer');

        if (who === 'customer') {
            whosEntering = true;
        } else if (who === 'staff') {
            whosEntering = true;
        } else {
            whosEntering = false;
        }
    });


    $('#begin_btn').on('click', function(e) {
        e.preventDefault();

        if (who === 'customer') {
            customer();
        }

        if (who === 'staff') {
            staff();
        }
    });

    $('#restart_btn').on('click', function(e) {
        who = $(this);
        who.closest('form')[0].reset();
        e.preventDefault();

        back();
    });
});

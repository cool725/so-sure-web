// user-bacs.js

// require('../../../sass/pages/user.scss');

// Require BS component(s)
// require('bootstrap/js/dist/tab');
// require('bootstrap/js/dist/util');
// require('bootstrap/js/dist/dropdown');
// require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validationMethods.js');
// require('jssocials');

const sosure = sosure || {};

sosure.purchaseStepBacs = (function() {
    let self = {};
    self.form = null;
    self.isIE = null;
    self.loader = null;
    self.webPay = null;
    self.webPayBtn = null;

    self.init = () => {
        self.form = $('.validate-form');
        self.sortCodeMask();
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        self.loader = $('#so-sure-loader');
        self.webPay = $('#webpay-form');
        self.webPayBtn = $('#to_judo_form_submit');
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
    }

    self.sortCodeMask = () => {
        // Mask sort code input
        $('.sort-code').mask('00-00-00');
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
                "bacs_form[accountName]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    fullName: true,
                    equalToIgnoreCase: '#policy_holder_name'
                },
                "bacs_form[sortCode]": {
                    required: true,
                },
                "bacs_form[accountNumber]": {
                    required: true,
                }
            },
            messages: {
                "bacs_form[accountName]": {
                    required: 'Please enter the name on the account',
                    fullName: 'Please enter your first and last name',
                    equalTo: 'The name on the account must match the policy holder'
                },
                "bacs_form[sortCode]": {
                    required: 'Please enter your sort code',
                },
                "bacs_form[accountNumber]": {
                    required: 'Please enter your account number',
                },
                "bacs_form[soleSignature]": {
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
    sosure.purchaseStepBacs.init();

});

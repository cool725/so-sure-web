// purchase.js

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
let textFit = require('textfit');

const sosure = sosure || {};

sosure.purchaseStepPledge = (function() {
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
            focusCleanup: true,
            onkeyup: false,
            onclick: false,
            rules: {

            },
            messages: {
                // Leave validation messages blank as class gets added to the label
                "purchase_form[agreedDamage]": {
                    required: ''
                },
                "purchase_form[agreedAgeLocation]": {
                    required: ''
                },
                "purchase_form[agreedExcess]": {
                    required: ''
                },
                "purchase_form[agreedTerms]": {
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

$(function(){

    textFit($('.fit')[0], {detectMultiLine: false});

    sosure.purchaseStepPledge.init();

});

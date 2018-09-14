// purchase.js

// require('../../sass/pages/purchase.scss');

// Require BS component(s)
require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('../../../js/Default/jqueryValidatorMethods.js');

let sosure = sosure || {};

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
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            // onclick: false,
            rules: {

            },
            messages: {

            },
            errorPlacement: function(error, element) {
                // error.insertAfter($(element).find('.custom-checkbox '));
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    return self;
})();

$(function(){
    sosure.purchaseStepPledge.init();

});

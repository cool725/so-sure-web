var sosure = sosure || {};

sosure.claim = (function() {
    var self = {};
    self.form = null;
    self.delayTimer = null;
    self.focusTimer = null;
    self.url = null;

    self.init = function() {
        self.form = $('.validate-form');
        self.whenMask();
        if (self.form.data('client-validation')) {
            self.addValidation();
        }
        self.url = window.location.href;
    }

    self.whenMask = function () {
        // Mask date input and add picker
        $('.date_mask').mask('00/00/0000');
        $('.time_mask').mask('00:00');
    }

    self.addValidation = function() {
        self.form.validate({
            debug: false,
            onkeyup: false,
            onclick: false,
            onfocusout: false,
            rules: {
                "claim_damage_form[typeDetails]" : {
                    required: true
                },
                "claim_damage_form[monthOfPurchase]" : {
                    required: true
                },
                "claim_damage_form[yearOfPurchase]" : {
                    required: true
                },
                "claim_damage_form[phoneStatus]" : {
                    required: true
                }
            },
            messages: {
                "claim_damage_form[typeDetails]" : {
                    required: 'Please select the type of damage'
                },
                "claim_damage_form[monthOfPurchase]": {
                    required: 'Please enter the month you bought your phone'
                },
                "claim_damage_form[yearOfPurchase]" : {
                    required: 'Please enter the month you bought your phone'
                },
                "claim_damage_form[phoneStatus]" : {
                    required: 'Please select the condition of your phone'
                }
            },

            submitHandler: function(form) {
                form.submit();
            },

            showErrors: function(errorMap, errorList) {
                this.defaultShowErrors();
                var vals = [];
                for (var err in errorMap) {
                    var val = $('body').find('input[name="' + err + '"]').val()
                    vals.push({'name': err, 'value': val, 'message': errorMap[err]});
                }
                $.ajax({
                  method: "POST",
                  url: "/ops/validation",
                  contentType:"application/json; charset=utf-8",
                  dataType:"json",
                  data: JSON.stringify({ 'errors': vals, 'url': self.url })
                });
            }
        });
    }

    self.removeValidation = function () {
        form.destroy();
    }

    return self;
})();

$(function(){
    sosure.claim.init();

    if ($('.has-error').length) {
        $('html,body').animate({
           scrollTop: $("#claim-form-container").offset().top
        });
    }

});

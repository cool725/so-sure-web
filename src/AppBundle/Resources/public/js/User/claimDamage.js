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

    self.removeValidation = function () {
        self.form.destroy();
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
            validClass: 'has-success',
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

    return self;
})();

$(function(){

    if ($('.has-error').length) {
        $('html,body').animate({
           scrollTop: $("#claim-form-container").offset().top
        });
    }

    $('#claim_damage_form_save').click(function(){
        sosure.claim.removeValidation();
        $('#claim_damage_form_isSave').attr('value', '1');
        $('#claim-form').submit();
    });

    $('#claim_damage_form_confirm').click(function(){
        sosure.claim.init();
        $('#claim_damage_form_isSave').attr('value', '0');
        $('#claim-form').submit();
    });

});

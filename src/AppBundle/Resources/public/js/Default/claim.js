var sosure = sosure || {};

sosure.claim = (function() {
    var self = {};
    self.form = null;
    self.delayTimer = null;
    self.focusTimer = null;
    self.name_email_changed = null;
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
        $('#claim_form_when').mask('00/00/0000');
    }

    self.addValidation = function() {
        self.form.validate({
            debug: false,
            onkeyup: false,
            focusCleanup: true,
            validClass: 'has-success',
            rules: {
                "claim_form[name]" : {
                    required: true,
                    fullName: true
                },
                "claim_form[email]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "claim_form[when]" : {
                    required: true,
                    validDate: true,
                    checkDateIsValid: true
                },
                "claim_form[phone]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneIntl: true,
                }
            },
            messages: {
                "claim_form[name]": {
                    required: 'Please enter your full name',
                    fullName: 'Please enter your first and last name'
                },
                "claim_form[email]" : {
                    required: 'Please enter a valid email address.'
                },
                "claim_form[when]" : {
                    required: 'Please enter a valid date in the format DD/MM/YYYY'
                },
                "claim_form[phone]" : 'Valid Phone Number'
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
    sosure.claim.init();
});

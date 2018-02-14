var sosure = sosure || {};

sosure.corporate = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
        self.form = $('.validate-form');
        if (self.form.data('client-validation')) {
            self.addValidation();
        }
    }

    self.addValidation = function() {
        self.form.validate({
            debug: false,
            focusCleanup: true,
            validClass: 'has-success',
            rules: {
                "lead_form[name]": {
                    required: true,
                    fullName: true
                },
                "lead_form[company]": {
                    required: true,
                },
                "lead_form[phone]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneIntl: true
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
                "lead_form[phones]": {
                    required: true,
                },
                "lead_form[timeframe]": {
                    required: true,
                },
                "lead_form[message]": {
                    required: true,
                }
            },
            messages: {
                "lead_form[name]": {
                    required: 'Please enter your full name',
                    fullName: 'Please enter your first and last name'
                },
                "lead_form[company]": {
                    required: 'Please enter your company name'
                },
                "lead_form[phone]": {
                    required: 'Please enter your phone number'
                },
                "lead_form[email]": {
                    required: 'Please enter a valid email address'
                },
                "lead_form[phones]": {
                    required: 'Please select no. of phones to insure'
                },
                "lead_form[timeframe]": {
                    required: 'Please enter when you wish to purchase'
                },
                "lead_form[message]": {
                    required: 'Please explain....'
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
    sosure.corporate.init();
});

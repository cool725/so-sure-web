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
        $('.date_mask').mask('00/00/0000');
        $('.time_mask').mask('00:00');
    }

    self.addValidation = function() {
        self.form.validate({
            debug: false,
            onkeyup: false,
            focusCleanup: false,
            onclick: false,
            rules: {
                "claim_form[signature]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    equalToIgnoreCase: '#username_signature'
                },
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
                "claim_form[phone]" : {
                    phoneUK: true,
                },
                "claim_form[timeToReach]" : {
                    required: true,
                },
                "claim_form[when]" : {
                    required: true,
                    validDate: true,
                    checkDateIsValid: true
                },
                "claim_form[time]" : {
                    required: true
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
                "claim_form[signature]" : {
                    required: 'Please sign the declaration'
                },
                "claim_form[name]": {
                    required: 'Please enter your full name',
                    fullName: 'Please enter your first and last name'
                },
                "claim_form[email]" : {
                    required: 'Please enter a valid email address.'
                },
                "claim_form[when]" : {
                    required: 'Please enter a valid date',
                    validDate: 'Please enter a valid date',
                    checkDateIsValid: 'Please enter a valid date',
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

    self.messageCharacters = function() {
        var text_max = 50;
        var text_length = $('#claim_form_message').val().length;
        var text_remaining = text_max - text_length;
        if (text_remaining < 0) {
            text_remaining = 0;
        }
        $('#claim-message-length').html('Minimum of 50 characters (' + text_remaining + ' remaining)');
    }

    return self;
})();

$(function(){
    sosure.claim.init();
});

$(function(){

    if ($('.has-error').length) {
        $('html,body').animate({
           scrollTop: $("#claim-form-container").offset().top
        });
    }

    $('#claim-back').click(function() {
        $('.tab-content').data('active', 'claimfnol');
        $('#tab-claimfnol-confirm').removeClass('active');
        $('#tab-claimfnol').addClass('active');
        return false;
    });

    sosure.claim.messageCharacters();
    $('#claim_form_message').keyup(function() {
        sosure.claim.messageCharacters();
    });
});

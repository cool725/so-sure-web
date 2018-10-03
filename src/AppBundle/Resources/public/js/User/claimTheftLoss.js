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
                "claim_theftloss_form[hasContacted]" : {
                    required: true
                },
                "claim_theftloss_form[contactedPlace]" : {
                    required: true
                },
                "claim_theftloss_form[blockedDate]" : {
                    required: true
                },
                "claim_theftloss_form[reportedDate]" : {
                    required: true
                },
                "claim_theftloss_form[reportType]" : {
                    required: true
                }
            },
            messages: {
                "claim_theftloss_form[hasContacted]" : {
                    required: 'Please select if you contacted the place'
                },
                "claim_theftloss_form[contactedPlace]": {
                    required: 'Please enter the name of the place you last had your phone'
                },
                "claim_theftloss_form[blockedDate]" : {
                    required: 'Please enter when you contacted your network provider'
                },
                "claim_theftloss_form[reportedDate]" : {
                    required: 'Please enter when you reported it'
                },
                "claim_theftloss_form[reportType]" : {
                    required: 'Please select how you reported it'
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

    $('#claim_theftloss_form_reportType').on('change', function(e) {
        if ($(this).val() == 'police-station') {
            $('#report-to-police').slideDown();
        } else {
            $('#report-to-police').slideUp();
        }
    });

});

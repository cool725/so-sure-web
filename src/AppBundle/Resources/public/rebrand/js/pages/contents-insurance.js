// contents-insurance.js

require('../../sass/pages/contents-insurance.scss');

require('jquery-validation');
require('../common/validation-methods.js');
const { ajax } = require('jquery');
let textFit = require('textfit');

$(function() {

  if ($('.fit').length) {
    textFit($('.fit'), {detectMultiLine: false});
  }

  let validateForm = $('.validate-form'),
      isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);

  const addValidation = () => {
    validateForm.validate({
      debug: true,
      // When to validate
      validClass: 'is-valid-ss',
      errorClass: 'is-invalid',
      onfocusout: false,
      onkeyup: false,
      rules: {
        "lead-email" : {
          required: {
            depends:function(){
                $(this).val($.trim($(this).val()));
                return true;
            }
          },
          email: true,
          emaildomain: true
        },
      },
      messages: {
        "lead-email" : {
          required: 'Please enter a valid email address.'
        },
      },

      submitHandler: function() {
        $('.lead-submit').prop('disabled', 'disabled');
        $('.lead-feedback').animate({opacity: 0});
        let data = {
          email: $('.lead-email').val(),
          csrf: validateForm.data('csrf')
        }
        $.ajax({
          url: validateForm.data('lead'),
          type: 'POST',
          data: JSON.stringify(data),
          contentType: "application/json; charset=utf-8",
          dataType: "json",
        })
        .done(function(data) {
          $('.lead-feedback').text('Thanks! We’ll keep you posted about the launch');
          $('.lead-email').prop('disabled', 'disabled');
        })
        .fail(function(data) {
          $('.lead-feedback').text('Something went wrong, please try again');
          $('.lead-email, .lead-submit').prop('disabled', '');
        })
        .always(function(){
          $('.lead-feedback').animate({opacity: 1});
        });
      }
    });
  }

  // Add validation
  if (validateForm.data('client-validation') && !isIE) {
    addValidation();
  }
});
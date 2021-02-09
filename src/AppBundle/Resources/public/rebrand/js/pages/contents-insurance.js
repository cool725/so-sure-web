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
    validateForm.each(function() {
      $(this).validate({
        debug: false,
        // When to validate
        validClass: 'is-valid-ss',
        errorClass: 'is-invalid',
        onfocusout: false,
        onkeyup: false,
        rules: {
          "hc-lead-email" : {
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
          "hc-lead-email" : {
            required: 'Please enter a valid email address.'
          },
        },

        submitHandler: function(form) {
          $(form).find('.hc-lead-submit').prop('disabled', 'disabled');
          $(form).find('.hc-lead-feedback').animate({opacity: 0});
          let data = {
            email: $(form).find('.hc-lead-email').val(),
            csrf: $(form).data('csrf')
          }
          $.ajax({
            url: $(form).data('lead'),
            type: 'POST',
            data: JSON.stringify(data),
            contentType: "application/json; charset=utf-8",
            dataType: "json",
          })
          .done(function(data) {
            $(form).find('.hc-lead-feedback').text('Thanks! We’ll keep you posted about the launch');
            $(form).find('.hc-lead-email').prop('disabled', 'disabled');
          })
          .fail(function(data) {
            $(form).find('.hc-lead-feedback').text('Something went wrong, please try again');
            $(form).find('.hc-lead-email, .hc-lead-submit').prop('disabled', '');
          })
          .always(function(){
            $(form).find('.hc-lead-feedback').animate({opacity: 1});
          });
        }
      });
    });
  }

  // Add validation
  if (validateForm.data('client-validation') && !isIE) {
    addValidation();
  }
});
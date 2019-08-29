// purchase.js

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('dot');
require('corejs-typeahead/dist/bloodhound.js');
require('corejs-typeahead/dist/typeahead.jquery.js');
require('jquery-mask-plugin');
require('fuse.js');
require('jquery-validation');
require('../common/validationMethods.js');
let textFit = require('textfit');

const sosure = sosure || {};

sosure.purchaseStepAddress = (function() {
    let self = {};
    self.form = null;
    self.delayTimer = null;
    self.focusTimer = null;
    self.name_email_changed = null;
    self.url = null;
    self.bloodhound = null;
    self.maxAddresses = 50; // more than 50 causes the find api to returns an error 'unrecognised country code'
    self.key = null;
    self.isIE = null;

   self.init = () => {
        self.form = $('.validate-form');
        self.dobMask();
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
        self.url = window.location.href;
        self.key = $('#ss-root').data('pca-key');
        self.init_bloodhound();
    }

    self.dobMask = () => {
        // Mask date input and add picker
        $('.dob').mask('00/00/0000');
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
            groups: {
                birthday: 'purchase_form_birthday_day purchase_form_birthday_month purchase_form_birthday_year',
            },
            rules: {
                "purchase_form[firstName]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    validFirstName: true
                },
                "purchase_form[lastName]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    validLastName: true
                },
                "purchase_form[email]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "purchase_form[birthday]" : {
                    required: true,
                    validDate: true,
                    checkDateOfBirth: true,
                    checkDateIsValid: true
                },
                "purchase_form[birthday][day]" : {
                    required: true
                },
                "purchase_form[birthday][month]" : {
                    required: true
                },
                "purchase_form[birthday][year]" : {
                    required: true,
                    checkDateOfBirthDropdown: ["#purchase_form_birthday_day", "#purchase_form_birthday_month", "#purchase_form_birthday_year"],
                    checkDateIsValidDropdown: ["#purchase_form_birthday_day", "#purchase_form_birthday_month", "#purchase_form_birthday_year"]
                },
                birthday : {
                    checkDateOfBirth: true
                },
                "purchase_form[mobileNumber]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneUK: true,
                },
                "purchase_form[addressLine1]" : {
                    required: true
                },
                "purchase_form[city]" :  {
                    required: true
                },
                "purchase_form[postcode]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    postcodeUK: true
                },
                "search_postcode" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    postcodeUK: true
                }
            },
            messages: {
                "purchase_form[firstName]": {
                    required: 'Please enter your first name',
                    validFirstName: 'Please enter a valid first name',
                },
                "purchase_form[lastName]": {
                    required: 'Please enter your last name',
                    validLastName: 'Please enter a valid last name',
                },
                "purchase_form[email]" : {
                    required: 'Please enter a valid email address.'
                },
                "purchase_form[birthday]" : {
                    required: 'Please enter a valid date in the format DD/MM/YYYY',
                },
                "purchase_form[birthday][day]" : {
                    required: 'Please enter your day of birth.'
                },
                "purchase_form[birthday][month]" : {
                    required: 'Please enter your month of birth.'
                },
                "purchase_form[birthday][year]" : {
                    required: 'Please enter your year of birth.'
                },
                "purchase_form[mobileNumber]" : 'Valid UK Mobile Number (Sorry for those outside the UK, but for now, we can only insure UK residents)',
                "purchase_form[addressLine1]" : 'Please enter the first line of your address',
                "purchase_form[city]" : 'Please enter your City',
                "purchase_form[postcode]" : 'Please enter a valid UK postcode'
            },

            errorPlacement: function(error, element) {
                if (element.attr('name') == 'purchase_form[birthday][day]' || element.attr('name') == 'purchase_form[birthday][month]' || element.attr('name') == 'purchase_form[birthday][year]') {
                    error.insertAfter('#dob-field');
                } else {
                    error.insertAfter(element);
                }
            },

            // Error Reporting
            showErrors: function(errorMap, errorList) {
                this.defaultShowErrors();
                let vals = [];
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
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    self.step_one_change = () => {
        self.name_email_changed = true;
    }

    self.step_one_continue = () => {
        if ($('#purchase_form_firstName').valid() == true && $('#purchase_form_lastName').valid() == true && $('#purchase_form_email').valid() == true) {
            $('.step--hide').show();
            $('#step--one-controls').hide();

            clearTimeout(self.delayTimer);
            if (self.name_email_changed) {
                self.name_email_changed = false;
                self.delayTimer = setTimeout(function() {
                    let data = {
                        name: $('#purchase_form_lastName').val(),
                        email: $('#purchase_form_email').val(),
                        csrf: $('#step--validate').data('csrf')
                    };
                    let url = $('#step--validate').data('lead');
                    $.ajax({
                        url: url,
                        type: 'POST',
                        data: JSON.stringify(data),
                        contentType: "application/json; charset=utf-8",
                        dataType: "json",
                    });
                }, 5000);
            }

            return true;
        } else {
            return false;
        }
    }

    self.step_address_continue = () => {
        if (self.form.valid()) {
            self.showAddress();
            return true;
        } else {
            return false;
        }
    }

    self.showAddress = (err) => {
        $('.address-search').hide();
        $('.typeahead').removeAttr('required');
        $('.address-show').show();
        if (err) {
            $('.address-show-error').show();
            $('.address-show-error-text').text(err);
        }
    }

    self.focusBirthday = () => {
        clearTimeout(self.focusTimer);
        self.focusTimer = setTimeout(function() {
            $('#purchase_form_birthday').focus();
        }, 300);
    }

    self.init_bloodhound = () => {
      self.bloodhound = new Bloodhound({
        datumTokenizer: Bloodhound.tokenizers.obj.whitespace('value'),
        queryTokenizer: Bloodhound.tokenizers.whitespace,
        remote: {
          url: "https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/json3.ws",
          prepare: function (query, settings) {
              if (query && (query.toLowerCase() == "bx11lt" || query.toLowerCase() == "bx1 1lt")) {
                  sosure.purchaseStepAddress.showAddress();
                  self.setAddress({'Line1': '123 test', 'City': 'Unknown', 'PostalCode': 'bx1 1lt'});
                  $('.typeahead .with-errors').html('');
              }
              settings.type = "POST";
              settings.data = {
                  Key: sosure.purchaseStepAddress.key,
                  SearchTerm: query,
                  Country : "GBR",
                  MaxSuggestions: sosure.purchaseStepAddress.maxAddresses
              };
              return settings;
          },
          transform: function (response) {
              if (response.Items && response.Items.length > 0 && response.Items[0].Error) {
                  sosure.purchaseStepAddress.showAddress("Sorry, there's an error with our address lookup. Please type in manually below.");
              }
              return response.Items;
          }
        }
      });
    }

    self.clearAddress = () => {
        self.setAddress({'Line1': '', 'Line2': '', 'Line3': '', 'City': '', 'Postcode': ''});
    }

    self.setAddress = (addr) => {
        if (!addr) {
            return;
        }
        let address = '';
        if (addr.Line1) {
            $('.addressLine1').val(addr.Line1);
            address = addr.Line1;
        }
        if (addr.Line2) {
            $('.addressLine2').val(addr.Line2);
            address = address + '<br>' + addr.Line2;
        }
        if (addr.Line3) {
            $('.addressLine3').val(addr.Line3);
            address = address + '<br>' + addr.Line3;
        }
        if (addr.City) {
            $('.city').val(addr.City);
            address = address + '<br>' + addr.City;
        }
        if (addr.PostalCode) {
            $('.postcode').val(addr.PostalCode);
            address = address + '<br>' + addr.PostalCode;
        }
        $('#display_address').html('<small class="form-text">Please double check</small>' + address);
        $('.typeahead .with-errors').html('');
    }

    self.toggleSearch = () => {
        if ($('#search_address_button').length > 0) {
            if ($('#search_address_button').html().indexOf('fa-search') >= 0) {
                $('#search_address_button').html('<i class="fa fa-spinner fa-spin"></i>');
            } else {
                $('#search_address_button').html('<i class="fa fa-search"></i>');
            }
        }
    }

    self.selectAddress = (suggestion) => {
        if (!suggestion) {
            $('#search_address_errors').show();
            $('#select_address_errors').show();
            $('.address-search').addClass('has-error');
            self.toggleSearch();

            return self.clearAddress();
        }
        if (suggestion.Next == "Retrieve") {
            return self.selectAddressFinal(suggestion);
        }
        $.ajax({
            method: "POST",
            url: "https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/json3.ws",
            data: {
                Key: sosure.purchaseStepAddress.key,
                LastId: suggestion.Id,
                SearchTerm: suggestion.Text,
                Country : "GBR",
                MaxSuggestions: sosure.purchaseStepAddress.maxAddresses
            }
        })
        .done(function( msg ) {
            sosure.purchaseStepAddress.selectAddressFinal(msg.Items[0]);
        });
    }

    self.selectAddressFinal = (suggestion) => {
        $.ajax({
            method: "POST",
            url: "https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/json3.ws",
            data: {
                Key: sosure.purchaseStepAddress.key,
                Id: suggestion.Id,
            }
        })
        .done(function( msg ) {
            $('#search_address_errors').hide();
            $('#select_address_errors').hide();
            $('.address-search').removeClass('has-error');
            self.toggleSearch();
            let addr = msg.Items[0];
            sosure.purchaseStepAddress.setAddress(addr);
            $.ajax({
                method: "POST",
                url: "/ops/postcode",
                contentType:"application/json; charset=utf-8",
                dataType:"json",
                data: JSON.stringify({ 'postcode': addr.PostalCode })
            });
        });
    }

    return self;
})();

$(function(){

    textFit($('.fit')[0], {detectMultiLine: false});

    sosure.purchaseStepAddress.init();

    $('#purchase_form_name').on('change', function() {
        sosure.purchaseStepAddress.step_one_change();
    });

    // Breaking validation setup
    // $('#purchase_form_name').on('blur', function() {
        // sosure.purchaseStepAddress.step_one_continue();
    // });

    $('#purchase_form_email').on('change', function() {
        sosure.purchaseStepAddress.step_one_change();
    });

    $('#purchase_form_email').on('blur', function(e) {
        let was_hidden = $('#step--one-controls').is(":visible");
        sosure.purchaseStepAddress.step_one_continue();
        if (was_hidden) {
            sosure.purchaseStepAddress.focusBirthday();
        }
    });

    $('#search_address_button').click(function(e) {
        e.preventDefault();
        let search_number = $('#search_address_number').val();
        let search_postcode = $('#search_address_postcode').val();
        let allow_search = search_number.length > 0 && search_postcode.length > 0;

        if (!allow_search) {
            return sosure.purchaseStepAddress.step_address_continue();
        }

        sosure.purchaseStepAddress.toggleSearch();

        $.ajax({
          method: "POST",
          url: "/ops/postcode",
          contentType:"application/json; charset=utf-8",
          dataType:"json",
          data: JSON.stringify({ 'postcode': search_postcode })
        }).done(function (response) {
            if (!response.postcode || response.postcode.length == 0) {
                return sosure.purchaseStepAddress.step_address_continue();
            }

            let search = search_number + ", " + response.postcode;
            sosure.purchaseStepAddress.bloodhound.search(search, function(sync) {}, function(async) {
                if (async.length > 0) {
                    sosure.purchaseStepAddress.selectAddress(async[0]);
                } else {
                    sosure.purchaseStepAddress.selectAddress(null);
                }
            });
        }).fail(function (response) {
            if (!response.postcode || response.postcode.length == 0) {
                sosure.purchaseStepAddress.selectAddress(null);
                return sosure.purchaseStepAddress.step_address_continue();
            }
        });
    });

    // Click check validate form?
    // Case: user clicks continue before filling in any fields
    $('#step--validate').on('click', function(e) {
        e.preventDefault();
        return sosure.purchaseStepAddress.step_one_continue();
    });

    $('#address-manual').click(function(e) {
        e.preventDefault();
        $('#address-select').rules("remove");
        $('#address-select').removeAttr("required");
        if (!sosure.purchaseStepAddress.step_address_continue()) {
            $('#address-select').attr("required", true);

            return false;
        }

        return true;
    });

    $('#search-address-manual').click(function(e) {
        e.preventDefault();
        $('#search_address_number').removeAttr("required");
        $('#search_address_number').rules("remove");
        $('#search_address_postcode').removeAttr("required");
        $('#search_address_postcode').rules("remove");
        if (!sosure.purchaseStepAddress.step_address_continue()) {
            $('#search_address_number').attr("required", true);
            $('#search_address_postcode').attr("required", true);

            return false;
        }

        return true;
    });

    $('.typeahead').typeahead(null, {
        name: 'capture',
        display: 'Text',
        source: sosure.purchaseStepAddress.bloodhound,
        highlight: true,
        limit: 100, // below 100 typeahead stops showing results for less than 4 characters entered
        templates: {
            notFound: [
              '<div class="empty-message">',
                'We couldn\x27t find that address. Make sure you have a space in the postcode (e.g SW1A 2AA). Or use manual entry.',
              '</div>'
            ].join('\n'),
            suggestion: doT.template('<div data-hj-suppress="">{{=it.Text}}</div>')
        }
    });

    //Suppress hotjar on input
    $('.tt-input').data('data-hj-suppress', '');

    $('.typeahead').bind('typeahead:select', function(ev, suggestion) {
        sosure.purchaseStepAddress.selectAddress(suggestion);
    });

});

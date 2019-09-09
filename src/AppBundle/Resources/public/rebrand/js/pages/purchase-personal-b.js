// purchase-personal-b.js

// Require BS component(s)
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

$(function(){

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
        url = window.location.href,
        key = $('#ss-root').data('pca-key'),
        bloodhound = null,
        // more than 50 causes the find api to returns an error 'unrecognised country code'
        maxAddresses = 50,
        leadSending = false,
        addressToggle = $('#address_manual_btn'),
        addressSelect = $('#address_select'),
        addressSearch = $('#address_search'),
        addressManual = $('#address_manual'),
        addressButton = $('#address_manual_btn'),
        addressSubmit = $('#address_submit_btn');

    const addValidation = () => {
        validateForm.validate({
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
                    error.insertAfter('#dob_field');
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

    // Initiate Bloodhound
    const initBloodHound = () => {
        bloodhound = new Bloodhound({
            datumTokenizer: Bloodhound.tokenizers.obj.whitespace('value'),
            queryTokenizer: Bloodhound.tokenizers.whitespace,
            remote: {
                url: "https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/json3.ws",
                prepare: function (query, settings) {
                    if (query && (query.toLowerCase() == "bx11lt" || query.toLowerCase() == "bx1 1lt")) {
                        showAddress();
                        setAddress({
                            'Line1': '123 test',
                            'City': 'Unknown',
                            'PostalCode': 'bx1 1lt'
                        });
                        $('.typeahead .with-errors').html('');
                        addressButton.hide();
                    }
                    settings.type = 'POST';
                    settings.data = {
                        Key: key,
                        SearchTerm: query,
                        Country : 'GBR',
                        MaxSuggestions: maxAddresses
                    };
                    return settings;
                },
                transform: function (response) {
                    if (response.Items && response.Items.length > 0 && response.Items[0].Error) {
                        $('#select_address_errors').html('Sorry, there\'s an error with our address lookup. Please type in manually below');
                    }
                    return response.Items;
                }
            }
        });
    }

    // Set address (forms hidden if using lookup so we need to set the values)
    const setAddress = (addr) => {
        if (!addr) {
            return;
        }
        let address = '';
        if (addr.Line1) {
            $('.addressLine1').val(addr.Line1);
        }
        if (addr.Line2) {
            $('.addressLine2').val(addr.Line2);
        }
        if (addr.Line3) {
            $('.addressLine3').val(addr.Line3);
        }
        if (addr.City) {
            $('.city').val(addr.City);
        }
        if (addr.PostalCode) {
            $('.postcode').val(addr.PostalCode);
        }
        $('.typeahead .with-errors').html('');
    }

    // Select the address
    const selectAddress = (suggestion) => {
        if (!suggestion) {
            console.log('No suggestion');
        }
        if (suggestion.Next == 'Retrieve') {
            return selectAddressFinal(suggestion);
        }
        $.ajax({
            method: 'POST',
            url: 'https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/json3.ws',
            data: {
                Key: key,
                LastId: suggestion.Id,
                SearchTerm: suggestion.Text,
                Country : 'GBR',
                MaxSuggestions: maxAddresses
            }
        })
        .done(function( msg ) {
            selectAddressFinal(msg.Items[0]);
        });
    }

    // Select the chosem address
    const selectAddressFinal = (suggestion) => {
        $.ajax({
            method: 'POST',
            url: 'https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/json3.ws',
            data: {
                Key: key,
                Id: suggestion.Id,
            }
        })
        .done(function(msg) {
            let addr = msg.Items[0];
            setAddress(addr);
            $.ajax({
                method: 'POST',
                url: '/ops/postcode',
                contentType:'application/json; charset=utf-8',
                dataType:'json',
                data: JSON.stringify({ 'postcode': addr.PostalCode })
            });
        });
    }

    // If address manual chosen
    const showAddress = () => {
        addressToggle.data('manual', true).text('Search address');
        addressSelect.removeAttr('required');
        addressSelect.rules('remove');
        addressManual.removeClass('hideme');
        addressSubmit.addClass('hideme');
        addressSearch.addClass('invisible');
    }

    // Start Bloodhound
    initBloodHound();

    // Mask date input
    $('.dob').mask('00/00/0000');

    // Add validation
    if (validateForm.data('client-validation') && !isIE) {
        addValidation();
    }

    // Lead - could be done better but we want to capture after entering not submitting
    $('#purchase_form_email').on('blur', function(e) {

        if (!leadSending) {
            if ($('#purchase_form_firstName').valid() == true &&
                $('#purchase_form_lastName').valid() == true &&
                $('#purchase_form_email').valid() == true) {

                console.log('Lead Start');

                // Take lead on post
                let leadData = {
                    name:  $('#purchase_form_firstName').val() + ' ' + $('#purchase_form_lastName').val(),
                    email: $('#purchase_form_email').val(),
                    csrf:  validateForm.data('csrf')
                };

                leadSending = true;

                $.ajax({
                    url: validateForm.data('lead'),
                    type: 'POST',
                    data: JSON.stringify(leadData),
                    contentType: "application/json; charset=utf-8",
                    dataType: "json",
                })
                .fail(function() {
                    leadSending = false;
                })
                .done(function() {
                    console.log('Lead sent');
                });
            }
        }
    });

    // Enter manually?
    addressButton.on('click', function(e) {
        e.preventDefault();
        showAddress();
        $(this).hide();
    });

    $('.typeahead').typeahead(null, {
        name: 'capture',
        display: 'Text',
        source: bloodhound,
        highlight: true,
        limit: 100, // below 100 typeahead stops showing results for less than 4 characters entered
        templates: {
            notFound: [
              '<div class="empty-message">',
                'We couldn\x27t find that address. Make sure you have a space in the postcode (e.g SW1A 2AA). Or enter manually.',
              '</div>'
            ].join('\n'),
            suggestion: doT.template('<div data-hj-suppress="">{{=it.Text}}</div>')
        }
    });

    // Suppress hotjar on input
    $('.tt-input').data('data-hj-suppress', '');

    // Bind suggestion to address function
    $('.typeahead').bind('typeahead:select', function(ev, suggestion) {
        selectAddress(suggestion);
    });

});

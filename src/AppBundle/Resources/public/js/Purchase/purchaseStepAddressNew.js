var sosure = sosure || {};

sosure.purchaseStepAddress = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
        self.form = $('.validate-form');
        self.dobMask();
        self.addValidation();
    }

    self.dobMask = function () {
        // Mask date input and add picker
        $('.dob').mask('00/00/0000');        
    }

    self.addValidation = function() {
        self.form.validate({
            debug: true,
            onfocusout: function(element) {
                this.element(element);
                // console.log('onfocusout fired');
            },
            validClass: 'has-success',
            rules: {
                "purchase_form[name]" : {
                    required: true,
                    fullName: true
                },
                "purchase_form[email]" : {
                    required: true,
                    email: true
                },
                "purchase_form[birthday]" : {
                    required: true,
                    validDate: true,
                    checkDateOfBirth: true
                },
                "purchase_form[mobileNumber]" : {
                    required: true,
                    phoneUK: true
                },
                "purchase_form[addressLine1]" : {
                    required: true
                },
                "purchase_form[city]" :  {
                    required: true
                },
                "purchase_form[postcode]" : {
                    required: true,
                    postcodeUK: true
                }
            },
            messages: {
                "purchase_form[name]": {
                    required: 'Please enter your full name',
                    fullName: 'Please enter your first and last name'
                },
                "purchase_form[email]" : {
                    required: 'Please enter your email address'
                },
                "purchase_form[birthday]" : {
                    required: 'Please enter a valid date in the format DD/MM/YYYY',
                    check_date_of_birth: 'Sorry, only persons over the age of 18 can be covered',
                },
                "purchase_form[mobileNumber]" : 'Valid UK Mobile Number (Sorry for those outside the UK, but for now, we can only insure UK residents)',
                "purchase_form[addressLine1]" : 'Please enter the first line of your address',
                "purchase_form[city]" : 'Please enter your City',
                "purchase_form[postcode]" : 'Please enter a valid UK postcode'
            },

            submitHandler: function(form) {
                form.submit();
            }

        });
    }

    self.step_one_continue = function() {
        if ($('#purchase_form_name').valid() == true && $('#purchase_form_email').valid() == true) {
            $('.step--hide').show();
            $('#step--one-controls').hide();
            var data = {
                name: $('#purchase_form_name').val(),
                email: $('#purchase_form_email').val(),
                csrf: $('#step--validate').data('csrf')
            };

            var url = $('#step--validate').data('lead');
            $.ajax({
                url: url,
                type: 'POST',
                data: JSON.stringify(data),
                contentType: "application/json; charset=utf-8",
                dataType: "json",
            });

            return true;
        } else {
            return false;
        }
    }

    return self;
})();

$(function(){
    sosure.purchaseStepAddress.init();
});

$(function(){

    // Reveal form when first two fields are valid
    $('#purchase_form_email').on('blur', function() {
        sosure.purchaseStepAddress.step_one_continue();
    });

    // Click check validate form?
    // Case: user clicks continue before filling in any fields
    $('#step--validate').on('click', function(e) {
        e.preventDefault();
        return sosure.purchaseStepAddress.step_one_continue();
    });

    var maxAddresses = 50; // more than 50 causes the find api to returns an error 'unrecognised country code'
    var key = $('#ss-root').data('pca-key');

    var showAddress = function(err) {
        $('.address-search').hide();
        $('.typeahead').removeAttr('required');
        $('.address-show').show();
        if (err) {
            $('.address-show-error').show();
            $('.address-show-error-text').text(err);
        }
    }

    var setAddress = function(addr) {
        if (!addr) {
            return;
        }
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
    }

    $('#address-manual').click(function(e) {
        e.preventDefault();
        showAddress();
    });

    var capture = new Bloodhound({
      datumTokenizer: Bloodhound.tokenizers.obj.whitespace('value'),
      queryTokenizer: Bloodhound.tokenizers.whitespace,
      remote: {
        url: "https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/json3.ws",
        prepare: function (query, settings) {
            if (query && (query.toLowerCase() == "bx11lt" || query.toLowerCase() == "bx1 1lt")) {
                showAddress();
                setAddress({'Line1': '123 test', 'City': 'Unknown', 'PostalCode': 'bx1 1lt'});
            }
            settings.type = "POST";
            settings.data = {
				Key: key,
				SearchTerm: query,
				Country : "GBR",
                MaxSuggestions: maxAddresses
            };
            return settings;
        },
        transform: function (response) {
            if (response.Items && response.Items.length > 0 && response.Items[0].Error) {
                showAddress("Sorry, there's an error with our address lookup. Please type in manually below.");
            }
            return response.Items;
        }
      }
    });
    $('.typeahead').typeahead(null, {
      name: 'capture',
      display: 'Text',
      source: capture,
      highlight: true,
      limit: 100 // below 100 typeahead stops showing results for less than 4 characters entered
    });
    $('.typeahead').bind('typeahead:select', function(ev, suggestion) {
        showAddress();
        $.ajax({
            method: "POST",
            url: "https://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/json3.ws",
            data: {
				Key: key,
				Id: suggestion.Id,
            }
          })
            .done(function( msg ) {
                var addr = msg.Items[0];
                setAddress(addr);
          });
    });
});

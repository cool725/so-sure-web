$(function(){

    $("#purchase_form_mobileNumber").intlTelInput({
        preferredCountries: ['gb'],
        initialCountry: 'gb',
        allowDropdown: false
    });

    $("#purchase_form_mobileNumber").on("countrychange", function(e, countryData) {
        setTimeout(function() {
            var country = $("#purchase_form_mobileNumber").intlTelInput("getSelectedCountryData");
            if (country.length == 0 || country.iso2 != 'gb') {
                $(".mobile-err").html("<ul><li>Sorry, we currently only support UK Residents</li></ul>");
            } else {
                $(".mobile-err").html("");
            }
        }, 1500);
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
            if (query && query.toLowerCase() == "bx11lt") {
                showAddress();
                setAddress({'Line1': '123 test', 'City': 'Unknown', 'PostalCode': 'bx11lt'});
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

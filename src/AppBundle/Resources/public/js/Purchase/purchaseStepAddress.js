$(function(){
    var maxAddresses = 60;
    var key = $('#ss-root').data('pca-key');

    $('.form-control').on('change', function() {
        $(this).parent().removeClass('has-error');
        $(this).parent().find('.with-errors').empty();
    });

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

    $('.address-manual').click(function(e) {
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
                Method: "Predict",
				SearchTerm: query,
				Country : "GBR",
                Limit: maxAddresses
            };
            return settings;
        },
        transform: function (response) {
            //console.log(response);
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
      limit: maxAddresses,
    });
    $('.typeahead').bind('typeahead:select', function(ev, suggestion) {
        console.log(suggestion);
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
                //console.log(msg);
                var addr = msg.Items[0];
                setAddress(addr);
          });
    });
});

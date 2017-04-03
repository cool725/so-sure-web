$(function(){
    var phones = $('#select-phone-data').data('phones');
    var updatePhones = function() {
        var make = $('.select-phone-make').val();
        var options = $(".select-phones");
        options.empty();
        if (make) {
            options.append($("<option />").val("").text('Select your ' + make + ' device'));
        } else {
            options.append($("<option />").val("").text('Select your phone make first'));
        }
        $.each(phones[make], function(key, value) {
            options.append($("<option />").val(key).text(value));
        });
    }
    $('.select-phone-make').on('change', function(e) {
        updatePhones();
    });
    updatePhones();

    $('#launch_phone_make').change(function() {
        $('#launch_phone_make').removeClass('has-error')
        $('#launch_phone_phoneId').removeClass('has-error')
    });

    $('#launch_phone_phoneId').change(function() {
        $('#launch_phone_phoneId').removeClass('has-error')
    });

    $("#launch_phone_next").click(function(event) {

        event.preventDefault();

        if ($('#launch_phone_make').val() == "") {
            $('#launch_phone_make').addClass('has-error')
        }
        else {
            $('#launch_phone_make').removeClass('has-error')
        }

        if ($('#launch_phone_phoneId').val() == "") {
            $('#launch_phone_phoneId').addClass('has-error')
        }
        else {
            $('#launch_phone_phoneId').removeClass('has-error')
        }

        if ($('#launch_phone_make').val() != "" && $('#launch_phone_phoneId').val() != "") {
            $('form[name="launch_phone"]').submit()
        }
    });

    // Twitter Typeahead
    function preventDefault(e) {
        e.preventDefault();
    }

    $('#search-phone-form').bind('submit', preventDefault);
    
    function mySort(a, b) {
        if (a < b) {
            return -1;
        } else if (a > b) {
            return 1;
        } else {
            return 0;
        }
    }

    var searchPhones = new Bloodhound({
        datumTokenizer: Bloodhound.tokenizers.obj.whitespace('name'),
        queryTokenizer: Bloodhound.tokenizers.whitespace,
        prefetch: { 'url': '/search-phone' },
        identify: function(obj) { return obj.id; },
        sorter: function(a, b) {
            var rxA = /(.*)\(([0-9]+)\s?GB\)/g;
            var rxB = /(.*)\(([0-9]+)\s?GB\)/g;
            var arrA = rxA.exec(a.name);
            var arrB = rxB.exec(b.name);
            if (arrA === null || arrB === null) {
                return mySort(a.name, b.name);
            } else if (arrA[1] == arrB[1]) {
                return mySort(parseInt(arrA[2]), parseInt(arrB[2]));
            } else {
                return mySort(arrA[1], arrB[1]);
            }
        }
    });


    $('#search-phone').typeahead({
        highlight: true,
        minLength: 1,
        hint: true,
    }, 
    {
        name: 'searchPhones',
        source: searchPhones,
        display: 'name',
        limit: 100,
    });


    // Stop the content flash when rendering the input
    $('#loading-search-phone').fadeOut('fast', function() {
        $('#search-phone-form').fadeIn();
    });

    $('#search-phone').bind('typeahead:selected', function(ev, suggestion) {
        $('#search-phone-form').unbind('submit', preventDefault);
    });

    $('#search-phone').bind('typeahead:select', function(ev, suggestion) {
        $('#search-phone-form').attr('action', '/phone-insurance/' + suggestion.id);
    });

});

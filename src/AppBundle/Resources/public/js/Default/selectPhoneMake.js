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

});

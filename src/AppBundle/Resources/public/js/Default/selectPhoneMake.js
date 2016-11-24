$(function(){
    var phones = $('#select-phone-data').data('phones');
    $('.select-phone-make').on('change', function(e) {
        var make = $('.select-phone-make').val();
        console.log(make);
        console.log(phones[make]);
        var options = $(".select-phones");
        options.empty();
        if (make) {
            options.append($("<option />").text('Select your ' + make + ' device'));
        } else {
            options.append($("<option />").text('Select your make first'));            
        }
        $.each(phones[make], function(key, value) {
            options.append($("<option />").val(key).text(value));
        });
    });
});
$(function(){

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 30,
        stagePadding: 100,
        items: 1,
        loop: true
    });

    $('.item').trigger('initialized.owl.carousel').show();

    // Extend Validation Rules
    jQuery.validator.addMethod('phoneUK', function(phone_number, element) {
    return this.optional(element) || phone_number.length > 9 &&
    phone_number.match(/^(\+44\s?7\d{3}|\(?07\d{3}\)?)\s?\d{3}\s?\d{3}$/);
    }, 'Please specify a valid phone number');

    jQuery.validator.addMethod("postcodeUK", function(value, element) {
    return this.optional(element) || /^[A-Z]{1,2}[0-9]{1,2} ?[0-9][A-Z]{2}$/i.test(value);
    }, "Please specify a valid Postcode");

    jQuery.validator.addMethod("check_date_of_birth", function (value, element) {
        if (this.optional(element)) {
            return true;
        }

        var dateOfBirth = value;
        var arr_dateText = dateOfBirth.split("/");
        day = arr_dateText[0];
        month = arr_dateText[1];
        year = arr_dateText[2];

        var mydate = new Date();
        mydate.setFullYear(year, month - 1, day);

        var maxDate = new Date();
        maxDate.setYear(maxDate.getYear() - 18);

        if (maxDate < mydate) {
            return false;
        }
        return true;
    }, 'Sorry, only persons over the age of 18 can be covered');


    // $('.form-control').on('change', function() {
    //     $(this).parent().removeClass('has-error');
    //     $(this).parent().find('.with-errors').empty();
    // });

});

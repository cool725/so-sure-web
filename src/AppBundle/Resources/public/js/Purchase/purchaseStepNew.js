$(function(){

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 30,
        stagePadding: 80,
        items: 2,
        loop: true
    });

    $('.item').trigger('initialized.owl.carousel').show();

    // Extend Validation Rules

    // Full name
    jQuery.validator.addMethod('fullName', function(value, element) {
    return this.optional(element) || value.match(/\w+\s+\w+/);
    }, 'Please enter your full name');

    // UK mobile number
    jQuery.validator.addMethod('phoneUK', function(phone_number, element) {
    return this.optional(element) || phone_number.length > 9 &&
    phone_number.match(/^(\+44\s?7\d{3}|\(?07\d{3}\)?)\s?\d{3}\s?\d{3}$/);
    }, 'Valid UK Mobile Number (Sorry for those outside the UK, but for now, we can only insure UK residents)');

    // UK Postcode
    jQuery.validator.addMethod("postcodeUK", function(value, element) {
    return this.optional(element) || /^([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-‌​9]{1,2})|(([AZa-z][0‌​-9][A-Za-z])|([A-Za-‌​z][A-Ha-hJ-Yj-y][0-9‌​]?[A-Za-z]))))[0-9][‌​A-Za-z]{2})$/.test(value);
    }, "Please specify a valid Postcode");

    // IMEI Number
    // jQuery.validator.addMethod("imei", function(value, element) {
    //     return this.optional(element) ||
    //     element.value === element.defaultValue ||
    //     $.validator.methods.creditcard.call(this, value, element);
    // // }, "Please enter a valid IMEI Number");
    // }, "Testing");

    // Alphanumeric check
    jQuery.validator.addMethod("alphanumeric", function(value, element) {
            return this.optional(element) || /^[a-zA-Z0-9]+$/.test(value);
    });

    // Date formate check
    jQuery.validator.addMethod('validDate', function(value, element) {
    return this.optional(element) || value.match(/^\d\d?\/\d\d?\/\d\d\d\d$/);
    }, 'Please enter a valid date in the format DD/MM/YYYY');

    // Over 18 Check
    jQuery.validator.addMethod("checkDateOfBirth", function (value, element) {
        if (this.optional(element)) {
            return true;
        }

        var dateOfBirth = value;
        var arr_dateText = dateOfBirth.split("/");
        day = arr_dateText[0];
        month = arr_dateText[1];
        year = arr_dateText[2];

        var birthdate = new Date();
        birthdate.setFullYear(year, month - 1, day);

        var minAge = new Date();
        minAge.setYear(minAge.getYear() - 18);

        if (minAge < birthdate) {
            return false;
        }
        return true;
    }, 'Sorry, only persons over the age of 18 can be covered');

});

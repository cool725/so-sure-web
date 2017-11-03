$(function(){
    // Extend Validation Rules

    // Full name
    jQuery.validator.addMethod('fullName', function(value, element) {
        return this.optional(element) || value.match(/\w+\s+\w+/);
    }, 'Please enter your full name');

    jQuery.validator.addMethod('emaildomain', function(value, element) {
        return this.optional(element) || value.match(/^\w+([-+.']\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/);
    }, 'Please enter a valid email address.');

    // UK mobile number
    jQuery.validator.addMethod('phoneUK', function(phone_number, element) {

        // Remove unicode characters
        phone_number = phone_number.replace(/[^ -~]/g, '');

        // Remove whitespace
        phone_number = phone_number.replace(/\s+/g, '');

        return this.optional(element) || phone_number.length > 9 &&
        phone_number.match(/^(0044\s?7\d{3}|\+44\s?7\d{3}|\(?07\d{3}\)?)\s?\d{3}\s?\d{3}$/);
    }, 'Valid UK Mobile Number (Sorry for those outside the UK, but for now, we can only insure UK residents)');

    // UK Postcode
    jQuery.validator.addMethod("postcodeUK", function(value, element) {
    return this.optional(element) || /^([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-9]{1,2})|(([A-Za-z][0-9][A-Za-z])|([A-Za-z][A-Ha-hJ-Yj-y][0-9]?[A-Za-z]))))[ ]?[0-9][A-Za-z]{2})$/.test(value);
    }, "Please specify a valid Postcode");

    // International mobile number
    jQuery.validator.addMethod('phoneIntl', function(phone_number, element) {
        return this.optional(element) || phone_number.length > 9 &&
        phone_number.match(/[-.+() 0-9]{7,20}/);
    }, 'Please enter a valid phone number');

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

    jQuery.validator.addMethod("checkDateIsValid", function (value, element) {

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

        var maxAge = new Date();
        maxAge.setFullYear(maxAge.getFullYear() - 150);

        if (maxAge > birthdate) {
            return false;
        }

        return true;
    }, 'Please enter a valid date of birth');

});

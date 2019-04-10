// validationMethods.js

const removeAccents = (text) => {
    let accents    = 'ÀÁÂÃÄÅàáâãäåÒÓÔÕÕÖØòóôõöøÈÉÊËèéêëðÇçÐÌÍÎÏìíîïÙÚÛÜùúûüÑñŠšŸÿýŽž',
        accentsOut = "AAAAAAaaaaaaOOOOOOOooooooEEEEeeeeeCcDIIIIiiiiUUUUuuuuNnSsYyyZz",
        textNoAccents = [];

    for (let i in text) {
        let idx = accents.indexOf(text[i]);
        if (idx != -1) {
            textNoAccents[i] = accentsOut.substr(idx, 1);
        } else {
            textNoAccents[i] = text[i];
        }
    }

    return textNoAccents.join('');
}


$(function(){
    // Extend Validation Rules

    // Fullname
    jQuery.validator.addMethod('fullName', function(value, element) {

        value = removeAccents(value);

        return this.optional(element) || value.match(/^[-'a-zA-Z]+\s[-'a-zA-Z]+\s?$/);

    }, 'Please enter your full name');

    // LastName
    jQuery.validator.addMethod('LastName', function(value, element, param) {

        lastName = value.split(' ').pop();
        policyName = $(param).val().split(' ').pop();

        // console.log('Param: ' + policyName, ' Word: ' + lastName);

        return this.optional(element) || (lastName.toLowerCase() == policyName.toLowerCase());

    }, 'Check name is correct on bank account');

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
        minAge.setFullYear(minAge.getFullYear() - 18);

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

    // Over 18 Check Dropdown
    jQuery.validator.addMethod("checkDateOfBirthDropdown", function (value, element, params) {

        if (this.optional(element)) {
            return true;
        }

        var day = params[0];
        var month = params[1];
        var year = params[2];

        day = parseInt($(day).val(), 10);
        month = parseInt($(month).val(), 10);
        year = parseInt($(year).val(), 10);

        var birthdate = new Date();
        birthdate.setFullYear(year, month - 1, day);

        var minAge = new Date();
        minAge.setFullYear(minAge.getFullYear() - 18);

        if (minAge < birthdate) {
            return false;
        }

        return true;
    }, 'Sorry, only persons over the age of 18 can be covered');

    jQuery.validator.addMethod("isOverFive", function (value, element) {
        if (value == 'less than 5') {
            $('.other-inputs').prop('disabled', true);
            return false;
        } else {
             $('.other-inputs').prop('disabled', false);
            return true;
        }
    }, 'Sorry, we require at least 5 company phones to generate a custom quote. You can purchase policies directly via our site in other cases.');

    jQuery.validator.addMethod('time', function(value, element, param) {
        return value == '' || value.match(/^([01][0-9]|2[0-3]):[0-5][0-9]$/);
    }, 'Please Enter a valid time: hh:mm');

    jQuery.validator.addMethod('imei', function(value, element) {
        var imei = value;
            imei = imei.replace('/', '');
            imei = imei.replace('-', '');
            imei = imei.replace(' ', '');
            imei = imei.substring(0, 15);

        // accept only digits, dashes or spaces
        if (/[^0-9-\s]+/.test(imei)) return false;

        // The Luhn Algorithm. It's so pretty.
        var nCheck = 0, bEven = false;
        imei.replace(/\D/g, "");

        for (var n = imei.length - 1; n >= 0; n--) {
            var cDigit = imei.charAt(n),
                nDigit = parseInt(cDigit, 10);

            if (bEven) {
                if ((nDigit *= 2) > 9) nDigit -= 9;
            }

            nCheck += nDigit;
            bEven = !bEven;
        }

        return (nCheck % 10) === 0;

    }, 'Please enter a valid IMEI number');

    jQuery.validator.addMethod('equalToIgnoreCase', function(value, element, param) {

        return this.optional(element) || (value.toLowerCase() == $(param).val().toLowerCase());

    }, 'Signature does not match name on policy');
});

// appendEmail.js

$(function() {

    // Use for plain text email
    let email  = $('.append-email'),
        domain = 'wearesosure.com';

    email.append('@' + domain);

    // Use for mailtolinks
    $('.mailto').on('click', function(e) {
        let href = $(this).attr('href');

        $(this).attr('href', href.replace('spam.so-sure.net', domain));
    });

});

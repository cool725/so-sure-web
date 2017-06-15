$(function(){

    // Payment buttons action radio buttons
    $('.payment--btn').click(function(event) {

        $(this).toggleClass('payment--btn-selected')
        .siblings()
        .removeClass('payment--btn-selected');

        var radio = $(this).data('target');

        $(radio).prop('checked', true);

    });

    $('#step--validate').click(function(e) {

        e.preventDefault();

    });


    // $('#policy-modal').on('show.bs.modal', function (event) {
    //     sosuretrack('View Full Policy');
    // });

    // if ($('#webpay-form').attr('action')) {
    //     $('#webpay-form').submit();
    // }

});

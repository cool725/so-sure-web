sosure.renew = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
    }

    self.clear_payment_buttons = function(element) {
        element
        .parent()
        .parent()
        .find('.payment--btn')
        .removeClass('payment--btn-selected');
        $('#renew_form_encodedAmount').val('');
        $('#renew_cashback_form_encodedAmount').val('');
    }

    self.use_cashback = function(use_cashback) {
        if (use_cashback) {
            $('#payment--reward-btns').hide();
            $('#payment--cashback-btns').show();
            $('#renew--continue-btn').hide();
            $('#renew--continue-cashback-btn').show();
        } else {
            $('#payment--reward-btns').show();
            $('#payment--cashback-btns').hide();
            $('#renew--continue-btn').show();
            $('#renew--continue-cashback-btn').hide();
        }
    }

    return self;
})();

$(function(){

    sosure.renew.init();

    // Init carousel
    $('.owl-carousel').owlCarousel({
        margin: 30,
        stagePadding: 70,
        items: 2,
        loop: true
    });

    $('.item').trigger('initialized.owl.carousel').show();

    $('#payment--cashback').click(function(event) {
        sosure.renew.clear_payment_buttons($(this));
        sosure.renew.use_cashback(true);
    });

    $('#payment--reward').click(function(event) {
        sosure.renew.clear_payment_buttons($(this));
        sosure.renew.use_cashback(false);
    });

    $('.payment--btn').click(function(event) {
        sosure.renew.clear_payment_buttons($(this));

        $(this).toggleClass('payment--btn-selected');

        var value = $(this).data('value');
        $('#renew_form_encodedAmount').val(value);
        $('#renew_cashback_form_encodedAmount').val(value);
    });

});

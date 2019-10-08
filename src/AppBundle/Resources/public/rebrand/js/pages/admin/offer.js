// offer.js

$(function() {
    // Set the right offer id on the add user modal.
    $('#user_modal').on('show.bs.modal', function(e) {
        let button = $(e.relatedTarget);
        let offer = button.data("offer");
        $("#add_user_offer_id").val(offer);
    });
});

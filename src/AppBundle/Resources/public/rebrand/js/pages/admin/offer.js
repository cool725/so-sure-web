/**
 * Makes the details modal look like it is loading.
 */
function detailsLoading() {
    let modal = $('#details_modal');
    let spinner = modal.find('details-spinner');
    let title = modal.find('details-title');
    spinner.show();
    title.hide();
}

/**
 * Renders the details modal with some data.
 * @param string name is the name of the offer that you are looking at.
 * @param data is a list containing objects that have a name field and a policies field, the policies field being an
 *             array of objects that have a policyNumber field and a start field.
 */
function detailsReady(name, data) {
    let modal = $('#details_modal');
    let spinner = modal.find('details-spinner');
    let title = modal.find('details-title');
    spinner.hide();
    title.show();
    title.text("good");
}

/**
 * Makes the details modal show an error message.
 * @param message is the error message to show.
 */
function detailsFailed(message) {
    let modal = $('#details_modal');
    let spinner = modal.find('details-spinner');
    let title = modal.find('details-title');
    spinner.hide();
    title.show();
    title.text(message);
    console.log("faile");
}

$(function() {
    // Set the right offer id on the add user modal.
    $("#user_modal").on("show.bs.modal", function(e) {
        let button = $(e.relatedTarget);
        let offer = button.data("offer");
        $("#add_user_offer_id").val(offer);
    });

    // Make the details modal load asynchronously.
    $("#details_modal").on("show.bs.modal", function(e) {
        let button = $(e.relatedTarget);
        detailsLoading();
        $.ajax(button.data("source"), {
            error: function(xhr, status, data) {
                detailsFailed("Could not get details of offer");
            },
            success: function(data, status, xhr) {
                // TODO: stuff.
                detailsReady();
            }
        });
    });
});

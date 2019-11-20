$(function() {
    let detailsModal = $('#details_modal');
    let detailsBody = detailsModal.find('.modal-body');
    let spinner = detailsModal.find('.details-spinner');
    let title = detailsModal.find('.details-title');
    let userList = detailsModal.find('.details-user-list');
    let policyList = detailsModal.find('.details-policy-list');

    /**
     * Makes the details modal look like it is loading.
     */
    function detailsLoading() {
        detailsBody.children().hide();
        spinner.show();
    }

    /**
     * Renders the details modal with some data.
     * @param data is expected to be an array containing a name, a list of users with email and id and a list of
     *             policies with policyNumber, start date, and id.
     */
    function detailsReady(data) {
        detailsBody.children().show();
        spinner.hide();
        title.text(data.name);
        userList.empty();
        policyList.empty();
        for (user of data.users) {
            let userItem = $(`<li><a href="/admin/user/${user.id}">${user.email}</a></li>`);
            userList.append(userItem);
        }
        for (policy of data.policies) {
            let policyItem = $(`<li><a href="/admin/user/${policy.id}">${policy.policyNumber}</a></li>`);
            userList.append(userItem);
        }
    }

    /**
     * Makes the details modal show an error message.
     * @param message is the error message to show.
     */
    function detailsFailed(message) {
        detailsModal.children().hide();
        title.show();
        title.text(message);
    }

    // Set the right offer id on the add user modal.
    $("#user_modal").on("show.bs.modal", function(e) {
        let button = $(e.relatedTarget);
        let offer = button.data("offer");
        $("#add_user_offer_id").val(offer);
    });

    // Make the details modal load asynchronously.
    detailsModal.on("show.bs.modal", function(e) {
        let button = $(e.relatedTarget);
        detailsLoading();
        $.ajax(button.data("source"), {
            error: function(xhr, status, data) {
                detailsFailed("Could not get details of offer, sorry");
            },
            success: function(data, status, xhr) {
                detailsReady(data);
            }
        });
    });
});

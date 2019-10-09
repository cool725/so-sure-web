$(function() {
    let detailsModal = $('#details_modal');
    let spinner = detailsModal.find('.details-spinner');
    let title = detailsModal.find('.details-title');
    let list = detailsModal.find('.details-list');

    /**
     * Makes the details modal look like it is loading.
     */
    function detailsLoading() {
        spinner.show();
        title.hide();
        list.empty();
    }

    /**
     * Renders the details modal with some data.
     * @param string name is the name of the offer that you are looking at.
     * @param data is a list containing objects that have a name field and a policies field, the policies field being
     * an array of objects that have a policyNumber field and a start field.
     */
    function detailsReady(data) {
        spinner.hide();
        title.show();
        title.text(data.name);
        for (user of data.users) {
            let userItem = $(`<li><a href="/admin/user/${user.id}">${user.email}</a></li>`);
            if (user.policies.length > 0) {
                let policyList = $("<ul></ul>");
                for (policy of user.policies) {
                    policyList.append(`<li><a href="/admin/policy/${policy.id}">${policy.policyNumber}</a>`);
                }
                userItem.append(policyList);
            }
            list.append(userItem);
        }
    }

    /**
     * Makes the details modal show an error message.
     * @param message is the error message to show.
     */
    function detailsFailed(message) {
        spinner.hide();
        title.show();
        title.text(message);
        console.log("faile");
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

// rewards.js

// Require components
require('tempusdominus-bootstrap-4');

$(function(){

    //Apply reward Modals
    $('#connect_modal').on('show.bs.modal', function (event) {
        let button = $(event.relatedTarget),
            rewardId = button.data('reward-id'),
            userName = button.data('user-name'),
            modal = $(this);

        if (rewardId) {
            modal.find('.modal-title').text('Add reward bonus (bonus type: ' + userName + ')');
            modal.find('#connectForm_rewardId').val(rewardId);
        }
    });

    // Init datepicker
    $('.date-picker').datetimepicker({
        useCurrent: false,
        format: 'DD/MM/YYYY',
    });

    // Submission alert
    $('#rewardForm_next').on('click', function(e) {
        e.preventDefault();

        if (confirm('Make sure you double check all details are correct!')) {
             $('#reward_form').submit();
        }
    });

    // Default T&Cs
    $('#default_terms').on('click', function(e) {
        e.preventDefault();

        let textArea = $('#rewardForm_termsAndConditions'),
            text = textArea.data('example');

        // Clear the terms then apply
        textArea.val(text);
    });

    // Add new Category to the select field
    $('#add-cat').click(function(){
        $('#error-cat').empty();
        let newCat = $('#new-cat').val();

        // Does the category already exists
        let exists = false;
        $('#rewardForm_type').each(function(){
            if (this.value == newCat) {
                exists = true;
                return false;
            }
        });

        //If not, add to the select and set as value
        if (!exists) {
            $("#rewardForm_type").append(new Option(newCat, newCat)).val(newCat);
            $('#new-cat').val('');
        } else{
            $('#error-cat').html('The category already exists');
        }
    });


});

// rewards.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
require('tempusdominus-bootstrap-4');
// e.g. require('../components/banner.js');

$(function(){

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

    $('#rewardForm_next').on('click', function(e) {
        e.preventDefault();

        if (confirm('Make sure you double check all details are correct!')) {
             $('#reward_form').submit();
        }
    });

    $('#default_terms').on('click', function(e) {
        e.preventDefault();

        let textArea = $('#rewardForm_termsAndConditions'),
            text = textArea.data('example');

        // Clear the terms then apply
        textArea.val(text);
    });

});

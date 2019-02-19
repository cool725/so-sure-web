// rewards.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
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

});

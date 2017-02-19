$('#connectModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var rewardId = button.data('reward-id');
  var userName = button.data('user-name');
  var modal = $(this);
  if (rewardId) {
    modal.find('.modal-title').text('Add reward bonus (bonus type: ' + userName + ')');
    modal.find('#connectForm_rewardId').val(rewardId);
  }
});

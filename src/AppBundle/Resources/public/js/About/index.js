$(function(){

	$('#team-member-info-modal').on('show.bs.modal', function(event) {

		var button = $(event.relatedTarget);
		var title  = button.data('team-member');
		var image  = button.data('team-member-img');
		var about  = button.data('team-member-bio');
		// Find the modal and swap the content
		var modal  = $(this);
		modal.find('.modal-title').text(title);
		modal.find('.modal-body img').attr({
			src: image,
			alt: title
		});
		modal.find('.modal-body p.bio').text(about);
	});


    $('#contact-us-intercom').click(function(e) {
        e.preventDefault();
        sosure.track.byName('Clicked Itercom Contact Us');
        Intercom('trackEvent', 'clicked intercom contact us');
        Intercom('showNewMessage', $(this).data('intercom-msg'));
    });
});

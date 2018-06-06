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
        Intercom('trackEvent', 'clicked intercom contact us');
        Intercom('showNewMessage', $(this).data('intercom-msg'));
    });

    if ($('#contact-us-form').length) {
        $('#contact-us-form').validate({
            debug: false,
            onkeyup: false,
            focusCleanup: true,
            validClass: 'has-success',
            rules: {
                "contact_form[name]" : {
                    required: true
                },
                "contact_form[email]" : {
                    required: true,
                    email: true
                },
                "contact_form[phone]" : {
                    required: true,
                    phoneIntl: true
                },
                "contact_form[message]" : {
                    required: true
                }
            },
            messages: {
                "contact_form[name]" : {
                    required: 'Please enter your name'
                },
                "contact_form[email]" : {
                    required: 'Please enter a valid email address',
                    email:  'Please enter a valid email address'
                },
                "contact_form[phone]" : {
                    required: 'Please enter a valid phone number',
                },
                "contact_form[message]" : {
                    required: "Please tell us what you'd like to talk about"
                }
            },
            submitHandler: function(form) {
                form.submit();
            }
        });
    }
});

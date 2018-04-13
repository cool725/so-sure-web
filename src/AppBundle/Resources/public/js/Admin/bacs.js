$('.bacs-submission').click(function() {
    if (confirm('Have you manually submitted this file to bacs?')) {
        var url = $(this).data('bacs-submission-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
               window.location.reload(false);
            }
        });
    }
});

$('.bacs-approve').click(function() {
    if (confirm('Are you sure you wish to approve this payment?')) {
        var url = $(this).data('bacs-approve-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location.reload(false);
            }
        });
    }
});

$('.bacs-reject').click(function() {
    if (confirm('Are you sure you wish to reject this payment?')) {
        var url = $(this).data('bacs-reject-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location.reload(false);
            }
        });
    }
});


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

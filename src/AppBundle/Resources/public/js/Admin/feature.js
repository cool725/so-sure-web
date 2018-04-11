$('.feature-active').click(function() {
    if (confirm('Are you sure you want to change this feature state?')) {
        var url = $(this).data('active');
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

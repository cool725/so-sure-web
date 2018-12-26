$('.delete-file').click(function() {
    var url = $(this).data('delete-url');
    if (confirm('Are you sure you wish to delete this file?')) {
        $.ajax({
            url: url,
            type: 'GET',
            success: function (result) {
                window.location.reload(true);
            },
            failure: function (result) {
                alert(result);
            }
        });
    }
});

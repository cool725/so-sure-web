// native-share.js

$('.native-share').on('click', function(e) {
    e.preventDefault();

    let nav_title = $(this).data('native-title'),
        nav_url = $(this).data('native-url'),
        nav_text = $(this).data('native-text');

    console.log(nav_title, nav_url, nav_text);

    // Check if native share is available
    if (navigator.share) {
        navigator.share({
            title: nav_title,
            url: nav_url,
            text: nav_text
        }).then(() => {
            alert('Thanks for sharing');
        }).catch(alert(error));
    } else {
        // console.log('Native share not available');
        // Needs some kinda default maybe just expand desktop share
        $(this).fadeOut().next('.non-native-share').removeClass('d-none').show();
    }

});

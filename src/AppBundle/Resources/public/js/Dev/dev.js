// dev.js
// Show it's dev environment in the console
console.log('%c so-sure dev ', 'background: #3399ff; color: #fff; font-size: 30px');

// Reload for dev bar
$('#dev-reload').on('click', function(e) {
    e.preventDefault();
    window.location.reload(true);
});

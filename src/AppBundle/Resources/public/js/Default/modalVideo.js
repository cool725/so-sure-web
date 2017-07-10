var iframe = document.querySelector('#exp-vid');
var player = new Vimeo.Player(iframe);

$('.modal-video').on('hidden.bs.modal', function() {
    player.pause();
})

$('.modal-video').on('shown.bs.modal', function() {
    player.play();
    sosure.track.byName('Watch Video');
})

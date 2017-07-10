$(function(){
  $('#pp-cfp').on('click', function() {
    sosure.track.byName('Feedback');
    $('#pp-cfp-trigger').click();
  });
});
(function(d){var s=d.createElement('script'),c=d.createElement('link');s.src='https://app.prodpad.com/static/js/prodpad-cfp.gz.js';s.async=1;c.href='https://app.prodpad.com/static/css/prodpad-cfp.gz.css';c.rel='stylesheet';document.head.appendChild(c);document.head.appendChild(s);})(document);
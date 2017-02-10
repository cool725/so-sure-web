$(function() {
  $('[data-toggle="popover"]').popover();

  if(window.location.hash && window.location.hash.startsWith('#claim_')) {
    $('.nav-tabs a[href="#claims"]').tab('show');
  }
});
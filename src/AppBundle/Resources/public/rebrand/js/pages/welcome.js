// welcome.js (oboarding)

require('../../sass/pages/welcome.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components;
// require('../components/onboarding.js');
let Clipboard = require('clipboard');

$(function() {

    // Open the policy summary on load
    $('#welcome_policy_summary').trigger('click');

    // Add copy btn for sharing link
    // new ClipboardJS('.btn-copy');
    let clipboard = new Clipboard('.btn-copy');

    $('.btn-copy').click(function(e) {
        e.preventDefault();
    });

    $('.btn-copy').tooltip({
        'title':'Copied',
        'trigger':'manual'
    });

    clipboard.on('success', function(event) {
        $('.btn-copy').tooltip('show');
        setTimeout(function() { $('.btn-copy').tooltip('hide'); }, 1500);
    });

    $('.qpw__main__container').scroll(function(e) {
        $('.navbar').toggleClass('navbar-scrolled-quote', $(this).scrollTop() > 5);
    });


    // Policy modal
    // Load Data
    const policyModal   = $('#policy_doc'),
          policyContent = $('.policy-content'),
          policyLoader  = $('#policy_doc_loader'),
          policyToggle  = $('#policy_doc_toggle'),
          policyReload  = $('#policy_doc_reload'),
          url           = policyModal.data('url');

    const loadDoc = () => {
        policyContent.load(url, function(responseTxt, statusTxt) {
            if (statusTxt === 'success') {
                // Hide the loader
                policyLoader.fadeOut();
                // Find the tables to add some styling classes
                $(this).find('h2').hide();
                $(this).find('table').addClass('table table-striped');
            }
            if (statusTxt === 'error') {
                policyLoader.fadeOut(function() {
                    policyReload.fadeIn();

                });
            }
        });
    }

    policyToggle.on('click', function(e) {
        e.preventDefault();
        loadDoc();
    });

    policyReload.on('click', function(e) {
        e.preventDefault();
        $(this).fadeOut();
        loadDoc();
    });

    // Show Starling Welcome
    // if ($('#starling-modal').length) {
        // TODO - Show this after onboarding
        // $('#starling-modal').modal('show');
    // } else {
    // $('#onboarding-modal').modal({
    //     show: true,
    //     keyboard: false,
    //     backdrop: 'static'
    // });
    // }

});

// picsure.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
// e.g. require('../components/banner.js');
require('@fancyapps/fancybox');

// Lazy load images
// require('intersection-observer');
// import lozad from 'lozad';

// const observer = lozad(); // lazy loads elements with default selector as '.lozad'
// observer.observe();

$(function(){

    $('#invalid_modal').on('show.bs.modal', function (event) {
        let button = $(event.relatedTarget),
            submit = button.data('submit'),
            policyNumber = button.data('policy-number'),
            modal = $(this);

        if (submit) {
            modal.find('#invalid-picsure-form').attr('action', submit);
            modal.find('.modal-title').text('Invalid pic-sure - ' + policyNumber);
        }
    });

    // Prepop
    $('#invalid_picsure_options').on('change', function(e) {
        e.preventDefault();

        let option = $(this).val();
        $('#message').val(option);
    });

    // Add options for fancybox
    $('[data-fancybox]').fancybox({
        buttons : [
            'zoom',
            'download',
            'thumbs',
            'close'
        ]
    });

});

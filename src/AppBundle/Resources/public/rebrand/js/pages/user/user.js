// faq.js

require('../../../sass/pages/user/user.scss');

// Require BS component(s)
require('bootstrap/js/dist/tab');
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/dropdown');
// require('bootstrap/js/dist/tooltip');

// Require components
// require('jquery-validation');
// require('jquery-mask-plugin');
// require('../../../../js/Default/jqueryValidatorMethods.js');
// require('jssocials');

$(function() {

    $('.show-tab').on('click', function (e) {
        e.preventDefault()
        let tab = $(this).data('tab-to');
        $(tab).tab('show')
    });

    let secondaryNav = $('#secondary_nav'),
        navPosition = $('#secondary_nav a.active').offset(),
        currentPos = navPosition.left;

    $(window).on('load', function(event) {
        secondaryNav.animate({scrollLeft: currentPos}, 1000);
    });

    // $('[data-toggle="tooltip"]').tooltip();

});

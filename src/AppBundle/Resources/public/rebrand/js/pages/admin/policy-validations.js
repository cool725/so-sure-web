// policy-validations.js

// require('../../../sass/pages/admin.scss');

// Require BS component(s)

// Require components


$(function(){

    let checkbox = $('#toggle_flags'),
        label = $('#flag_count'),
        flagged = $('.flagged'),
        count = flagged.length;

    const checked = () => {
        if (checkbox.prop('checked')) {
            flagged.parent().parent().hide();
        } else {
            flagged.parent().parent().show();
        }
    }

    label.text('(' + count + ')');

    checkbox.on('click', function(e) {
        checked();
    });

});

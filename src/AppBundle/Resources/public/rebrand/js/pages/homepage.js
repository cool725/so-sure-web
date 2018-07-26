// homepage.js

require('../../sass/pages/homepage.scss');

// Require components
require('../components/phoneSearchDropdown.js');

$(function() {

    // Sticky Search
    let searchBox  = $('#phone-search-component'),
        searchTop  = searchBox.position(),
        searchTog  = $('#search_toggle'),
        searchTogA = $('#search_toggle a');
        searchTogI = $('#search_toggle a i');

    // Page modal
    $('#modalHome').on('show.bs.modal', function(e) {

        // e.preventDefault();

        let button = $(e.relatedTarget),
            title  = button.attr('title'),
            body   = button.data('body'),
            modal  = $(this);

        modal.find('.modal-title').text(title);
        modal.find('.modal-body').text(body);
    });

});

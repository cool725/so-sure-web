
var sosure = sosure || {};

sosure.selectPhoneMake = (function() {
    var self = {};

    self.fuse_options = {
      keys: ['name'],
      shouldSort: true,
      threshold: 0.4,
    }
    self.delayTimer = null;
    self.sentMixpanel = false;

    self.init = function() {
        // make sure we always have a fuse to query against, even if no data
        self.fuse = new Fuse([], self.fuse_options);
        self.load_fuse();
    }
    
    self.sendSearch = function(page) {
        clearTimeout(self.delayTimer);
        self.delayTimer = setTimeout(function() {
            dataLayer.push({
              event: 'Search',
                'GAPage':page
            });
            //console.log(page);
        }, 1000);
        if (!self.sentMixpanel) {
            self.sentMixpanel = true;
            sosuretrack('Start Search');
        }
    }

    self.load_fuse = function() {
        $.ajax({
            url: '/search-phone',
            type: 'GET',
            success: function(result) {
                self.fuse = new Fuse(result, self.fuse_options);
            }.bind(self)
        });        
    }

    self.searchPhonesWithGa = function (q, sync) {
        var page = '/search?q=' + q;
        self.sendSearch(page);
        if (typeof self.fuse !== 'undefined') {
            results = self.fuse.search(q);
            //console.log(results);
            sync(results);
        } else {
            alert('no fuse');
        }
    }

    // Twitter Typeahead
    self.preventDefault = function(e) {
        e.preventDefault();
    }

    return self;
})();

$(function(){
    sosure.selectPhoneMake.init();
});

$(function(){

    // If the form action is already defined, then allow the form to submit
    if (!$('#search-phone-form').attr('action')) {
        $('#search-phone-form').bind('submit', sosure.selectPhoneMake.preventDefault);
    }

    $('#search-phone').typeahead({
        highlight: true,
        minLength: 1,
        hint: true,
    },
    {
        name: 'searchPhonesWithGa',
        source: sosure.selectPhoneMake.searchPhonesWithGa,
        display: 'name',
        limit: 100,
        templates: {
            notFound: [
              '<div class="empty-message">',
                'We couldn\x27t find that phone. Try searching for the make (e.g. iPhone 7), or <a href="mailto:hello@wearesosure.com" class="open-intercom">ask us</a>',
              '</div>'
            ].join('\n')
        }
    });

    // Stop the content flash when rendering the input
    $('#loading-search-phone').fadeOut('fast', function() {

        $('#search-phone-form').fadeIn();

        if(window.location.href.indexOf('?quote=1') != -1) {
            $('#search-phone').focus();
            sosuretrack('Get A Quote Link');
        }
    });

    $('#search-phone').bind('typeahead:selected', function(ev, suggestion) {
        $('#search-phone-form').unbind('submit', sosure.selectPhoneMake.preventDefault);
    });

    $('#search-phone').bind('typeahead:select', function(ev, suggestion) {
        var base_path = $('#search-phone-form').data('base-path');
        if (!base_path) {
            base_path = '/phone-insurance/';
        }
        $('#search-phone-form').attr('action', base_path + suggestion.id);
    });

});

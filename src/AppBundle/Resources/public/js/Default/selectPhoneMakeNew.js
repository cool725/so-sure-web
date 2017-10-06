
var sosure = sosure || {};

sosure.selectPhoneMake = (function() {
    var self = {};

    self.fuse_options = {
      keys: ['name'],
      shouldSort: true,
      threshold: 0.4,
      tokenize: true
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
            sosure.track.byName('Start Search');
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
            sync(null);
        }
    }

    self.searchExact = function (q, sync) {
        if (typeof self.fuse !== 'undefined') {
            results = self.fuse.search(q);
            if (results.length > 0 && results[0].name == q) {
                sync(results[0]);
            } else {
                sync(null);
            }
        } else {
            sync(null);
        }
    }

    self.setFormAction = function (id, form) {
        var base_path = $(form).data('base-path');
        var path_suffix = $(form).data('path-suffix');
        if (!base_path) {
            base_path = '/phone-insurance/';
        }
        $(form).attr('action', base_path + id + path_suffix);
    }

    self.setFormActionVal = function (form, input) {
        if ($(form).attr('action')) {
            return;
        }
        var q = $(input).val();
        sosure.selectPhoneMake.searchExact(q, function(result) {
            if (result && result.id) {
                sosure.selectPhoneMake.setFormAction(result.id);
                $(form).unbind('submit', sosure.selectPhoneMake.preventDefault);
            }
        });
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

    var $typeahead = $('[id^=search-phone-form]');

    // Needs work
    // if(window.location.href.indexOf('?quote=1') != -1) {
    //     $('.search-phone:first').focus();
    //     sosure.track.byName('Get A Quote Link');
    // }

    $.each($typeahead, function (index, typeahead){

        var form    = $(this);
        var input   = $(this).find('.search-phone');
        var loading = $(this).next('.so-sure-loading');

        // If the form action is already defined, then allow the form to submit
        if (!$(form).attr('action')) {
            $(form).bind('submit', sosure.selectPhoneMake.preventDefault);
            setTimeout(function () { sosure.selectPhoneMake.setFormActionVal(form, input); }, 3000);
        }

        $(input).typeahead({
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
        $(loading).fadeOut('slow');
        $(form).fadeIn('fast');

        $(input).bind('typeahead:selected', function(ev, suggestion) {
            $(form).unbind('submit', sosure.selectPhoneMake.preventDefault);
        });

        $(input).bind('typeahead:select', function(ev, suggestion) {
            sosure.selectPhoneMake.setFormAction(suggestion.id, form);
        });

        $(input).bind('typeahead:change', function(ev, suggestion) {
            sosure.selectPhoneMake.setFormActionVal(form, input);
        });

    });

});

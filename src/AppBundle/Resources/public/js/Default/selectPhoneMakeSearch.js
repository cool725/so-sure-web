var sosure = sosure || {};

sosure.selectPhoneMake = (function() {
    var self = {};

    self.fuse_options = {
      keys: [{name:'name',weight: 0.3}, {name:'highlight',weight: 0.7}],
      shouldSort: true,
      threshold: 0.4,
      tokenize: true,
      includeScore: true
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
            url: '/search-phone-combined',
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
            //results.sort(function(a, b){return b.score - a.score});
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

    self.getFormAction = function (id, form) {
        var base_path = $(form).data('base-path');
        var path_suffix = $(form).data('path-suffix');
        if (!base_path) {
            base_path = '/phone-insurance/';
        }
        return base_path + id + path_suffix;
    }

    self.setFormAction = function (id, form) {
        $(form).attr('action', self.getFormAction(id, form));
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
    if ($('.search-phone-form').length) {
        sosure.selectPhoneMake.init();
    }
});

$(function(){

    var $typeahead = $('[id^=search-phone-form]');

    $.each($typeahead, function (index, typeahead){

        var form    = $(this);
        var base_path = $(form).data('base-path');
        var path_suffix = $(form).data('path-suffix');
        var input   = $(this).find('.search-phone');

        // Fallback incase not set
        if (!base_path) {
            base_path = '/phone-insurance/';
        }

        // If the form action is already defined, then allow the form to submit
        if (!$(form).attr('action')) {
            $(form).bind('submit', sosure.selectPhoneMake.preventDefault);
            setTimeout(function () { sosure.selectPhoneMake.setFormActionVal(form, input); }, 3000);
        }

        $(input).typeahead({
            highlight: true,
            minLength: 1,
            hint: true,
            autoselect: false,
        },
        {
            name: 'searchPhonesWithGa',
            source: sosure.selectPhoneMake.searchPhonesWithGa,
            display: 'name',
            limit: 100,
            templates: {
                notFound: [
                  '<div class="empty-message">',
                    'We couldn\x27t find that phone. <br> Try searching for the make (e.g. iPhone 7), or <a href="mailto:hello@wearesosure.com" class="open-intercom">ask us</a>',
                  '</div>'
                ].join('\n'),
                header: [
                    '<div class="tt-menu-header clearfix">',
                        '<div class="tt-menu-left">FIND YOUR MODEL</div>',
                        '<div class="tt-menu-right hidden-xs hidden-sm"><i class="fa fa-angle-down" aria-hidden="true"></i>  SELECT FOR INSTANT QUOTE <i class="fa fa-angle-down" aria-hidden="true"></i></div>',
                    '</div>'
                ].join('\n'),
                //suggestion: doT.template('<div class="clearfix"><div class="tt-menu-left tt-menu-pad">{{=it.item.name}} - {{=it.score}}</span></div><div class="tt-menu-right">{{~it.item.sizes :value}}<a href="'+base_path+'{{=value.id}}'+path_suffix+'" class="btn-tt">{{=value.memory}}GB</a> {{~}}</div></div>')
                suggestion: doT.template('<div class="clearfix"><div class="tt-menu-left tt-menu-pad" data-score="{{=it.score}}">{{=it.item.name}}</div><div class="tt-menu-right">{{~it.item.sizes :value}}<a href="'+base_path+'{{=value.id}}'+path_suffix+'" class="btn-tt">{{=value.memory}}GB</a> {{~}}</div></div>')
            }
        });

        // Stop the content flash when rendering the input
        $(form).css('visibility', 'visible').animate({opacity: 1}, 500);

        $(input).bind('typeahead:selected', function(ev, suggestion) {
            $(form).unbind('submit', sosure.selectPhoneMake.preventDefault);
        });

        // On select add class to row
        $(input).bind('typeahead:select', function(ev, suggestion) {

            var form = $(ev.target).closest('form');

            sosure.selectPhoneMake.setFormAction(suggestion.item.id, form);

            var path_suggestion = sosure.selectPhoneMake.getFormAction(suggestion.item.id, form);

            // Check memory options
            var link_count = suggestion.item.sizes.length;

            // If less than 1 forward to option when selected anywhere on the suggestion
            if (link_count == 1) {
                var link_to_go = $('.tt-suggestion').find('a[href="'+path_suggestion+'"]').attr('href');
                window.location.href = link_to_go;
            } else {
                // If more than 1 option highlight the buttons to alert the user there are options
                $('.tt-suggestion').find('a[href="'+path_suggestion+'"]').parent().parent().addClass('tt-selected').siblings().removeClass('tt-selected');
            }
            menuHold();
        });

        $(input).bind('typeahead:change', function(ev, suggestion) {
            sosure.selectPhoneMake.setFormActionVal(form, input);
        });

        // Keeps the menu open whilst the new page loads
        function menuHold() {
            // Prevent default behaviour
            $(input).bind('typeahead:beforeclose', function(ev, suggestion) {
                ev.preventDefault();

                // Add back behavior on click off
                $(document).click(function(event) {
                    $(input).unbind('typeahead:beforeclose');
                });
            });
        }

        if (index == 0) {
            // Focus Search Box if url param
            if (window.location.href.indexOf('?quote=1') != -1) {
                $(input).focus();
            }
        }
    });

    if ($('#quoteModal').length) {
        $('#quoteModal').on('shown.bs.modal', function() {
            $('.search-phone-text').focus();
        });
    }

    // Load policy doc
    if ($('.modal-policy').length) {
        $('#policy-modal').on('shown.bs.modal', function() {

            // Content to load
            var url = $('.modal-policy').data('url');

            // Append to body
            $(this).find('.modal-body').load(url, function(responseTxt, statusTxt, xhr){
                if (statusTxt == 'success') {
                    sosure.globals.policyTerms();
                }
                // If error show reload button
                if (statusTxt == 'error') {
                    $('#reload-policy').show();
                }
            });

            // Try and reload
            $('#reload-policy').click(function(event) {

                $('#reload-policy').hide();

                $('.modal-body').load(url, function(responseTxt, statusTxt, xhr) {
                    if (statusTxt == 'success') {
                        sosure.globals.policyTerms();
                    }
                    if (statusTxt == 'error') {
                        $('#reload-policy').show();
                    }
                });
            });
        });
    }
});

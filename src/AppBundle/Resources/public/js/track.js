var sosure = sosure || {};

sosure.track = (function() {
    var self = {};
    self.form = null;

    self.init = function() {
    }

    self.byName = function(name, callback) {
        var url = '/ops/track/' + name;
        $.get(url, callback);
    }

    self.byLocationInvite = function (name, location, callback) {
        var url = '/ops/track/invite/' + name + '/' + location;
        $.get(url, callback);
    }

    self.byScode = function (location, callback) {
        var url = '/ops/track/scode/' + location;
        $.get(url, callback);
    }

    self.byScode = function (location, callback) {
        var url = '/ops/track/scode/' + location;
        $.get(url, callback);
    }

    return self;
})();

$(function(){
    sosure.track.init();
});

$(function(){
    $('.sosure-track').on('click', function(event) {
        event.preventDefault();
        var name = $(this).data('event');
        var url = $(this).data('event-url');
        sosure.track.byName(name, function() {
            if (url) {
                window.location = url;
            }
        });
    });

    $('.sosure-track-intercom').on('click', function(event) {
        event.preventDefault();
        var name = $(this).data('event');
        if (typeof Intercom !== 'undefined') {
            Intercom('trackEvent', name);
        }
    });
});

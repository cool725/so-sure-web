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

    self.byInvite = function (name, callback) {
        var url = '/ops/track/invite/' + name;
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
    })
});

// homepage.js
$(function(){

    // Enhance the focus on the search box when input focused
    $('.search-phone').each(function(index, el) {
        var container = $(this).parents('.search-phone-form');
        $(this).on('focus', function(){
            // Add focus style
            $(container).addClass('search-phone-form--focus');
        }).on('blur', function(){
            $(container).removeClass('search-phone-form--focus');
        });
    });

    // Stuck, replace affix for non iOS
    var device = navigator.userAgent.toLowerCase();
    var agentID = device.match(/(iphone|ipod|ipad)/);

    $(window).bind('load', function() {

        // If iOS
        if (agentID) {
            // Offset of search from top of page once page is loaded
            var stickyOffset = $('#select-phone-data').offset().top;

            // Init BS affix
            $('#select-phone-data').affix({
                offset: {
                    top: stickyOffset + 50,
                    bottom: function () {
                        return (this.bottom = $('footer').outerHeight(true) + 1000)
                    }
                }
            }).on('affixed.bs.affix', function() {
                // This event is fired after the element has been affixed.

                // Add animation
                $(this).addClass('animated fadeInDown');

            }).on('affix-top.bs.affix',function() {
                // This event fires immediately before the element has been affixed-top.

                // Remove animation to refire
                $(this).removeClass('animated fadeInDown');
            });
        // If anything else
        } else {

            var target = $('#select-phone-data');

            function stuck() {
                var height = $(window).scrollTop();
                var position = target.position();
                var subClass = null;

                if (height > position.top) {
                    target.addClass('stuck animated fadeInDown');
                } else {
                    target.removeClass('stuck animated fadeInDown');
                }
            }

            if (target.length) {
                $(window).scroll(stuck);
            }
        }

    });
});

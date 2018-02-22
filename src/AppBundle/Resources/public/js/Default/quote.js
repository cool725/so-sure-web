// quote.js
$(function(){

    var quoteSticky = $('.quote__left');

    // Init affix
    $(quoteSticky).affix({
        offset: {
            top: 0,
            bottom: function () {
                return (this.bottom = $('footer').outerHeight(true) + 25)
            }
        }
    });

    // Resize box because bootstrap!
    parent = $(quoteSticky).parent();
    resize = function() {
        $(quoteSticky).width($(parent).width());
    }
    $(window).resize(resize);
    resize();

});

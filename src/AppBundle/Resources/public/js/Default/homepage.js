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

    // Single MEM Option/Look Test
    // Add test layer
    var memOptTest = $('#select-phone-data').data('show-single-mem-opt');
    var memory     = $('.select-phone-memory');

    // Offset of search from top of page
    var stickyOffset = $('#select-phone-data').offset().top;

    // Init BS affix
    $('#select-phone-data').affix({
        offset: {
            top: stickyOffset + 50,
            bottom: function () {
                return (this.bottom = $('footer').outerHeight(true) + 1000)
            }
        }
    }).on('affixed.bs.affix', function(e) {
        // This event is fired after the element has been affixed.

        // Add animation
        $(this).addClass('animated fadeInDown');

    }).on('affix-top.bs.affix',function() {
        // This event fires immediately before the element has been affixed-top.

        // Remove animation to refire
        $(this).removeClass('animated fadeInDown');

    });
});

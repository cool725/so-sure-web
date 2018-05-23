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

    // Sticky search - now using affix BS
    var stickySearch = $('#select-phone-data').data('sticky-search');

    // Single MEM Option/Look Test
    // Add test layer
    var memOptTest = $('#select-phone-data').data('show-single-mem-opt');

    if (stickySearch) {

        // Offset of search from top of page
        var stickyOffset = $('#select-phone-data').offset().top;
        var collapsed = false;

        // Init BS affix
        $('#select-phone-data').affix({
            offset: {
                top: stickyOffset + 700,
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

            if (memOptTest == true) {
                showOptions();
            }

            // Remove animation to refire
            $(this).removeClass('animated fadeInDown');

        });

        // Setup test
        if (memOptTest == true) {
            var phone    = $('#phone');
            var make     = $('.select-phone-make');
            var intMake  = false;
            var model    = $('.select-phone-model');
            var intModel = false;
            var memory   = $('.select-phone-memory');
            var intMemory = false;
            var controls   = $('#quote-controls');
            var pullDown = $('#phone-pull-down');

            function collapseOptions() {
                if (intMake) {
                    phone.show();
                    model.slideUp();
                    make.slideUp();
                    controls.slideUp();
                    memory.slideUp();
                    pullDown.hide();

                    collapsed = true;
                }
            }

            function showOptions() {
                make.show();
                if (intMake) {
                    model.show();
                }
                if (intModel) {
                    memory.show();
                }
                if (intMemory) {
                    controls.show();
                }
                phone.hide();
                pullDown.hide();

                collapsed = false;
            }

            function scrollCheck() {
                if (make.val() != '') {
                    intMake = true;

                    if ($('.affix').length && collapsed == false) {
                        pullDown.css('display','block');
                    }
                }
                if (model.val() != '') {
                    intModel = true;
                }
                if (memory.val() != '') {
                    intMemory = true;

                    // If all options selected allow btn to work
                    $('#phone-btn').show();
                    $('#expand-btn').hide();

                    // Mimic the click
                    $('#phone-btn').click(function() {
                        $('#launch_phone_next').click();
                        collapseOptions();
                    });
                } else {
                    $('#phone-btn').hide();
                    $('#expand-btn').show();
                }
            }

            // Fire checks on scroll
            $(window).scroll(scrollCheck);

            // Collapse on click
            $(pullDown).on('click', function(e) {
                // Prevent anchor
                e.preventDefault();
                // Collapse options
                collapseOptions();
            });

            // Trigger to show again
            $('#phone-name, #expand-btn').click(function(e) {
                // Prevent anchor
                e.preventDefault();
                // Show options
                showOptions();
            });
        }
    }

});

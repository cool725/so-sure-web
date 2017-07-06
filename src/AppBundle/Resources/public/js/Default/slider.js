$(function(){

    // Expand The Range element for customisation
    $element = $('input[type="range"]');

    // Slider Vars
    var current_value    = $('#reward-slider').val();
    var max_value        = $('#reward-slider').prop('max');
    var maxpot_value     = $('#reward-slider').data('maxpot');
    var connection_value = $('#reward-slider').data('connection-value');
    var montly_premium   = $('#reward-slider').data('premium');
    var claim_check      = $('#claim-check').data('cashback-check');
    var yearly_high      = $('#reward-slider').data('max-comparison');

    // Page Vars
    var cashback         = $('#cashback');
    var cashback_new     = $('#cashback-with-claim');
    var cashback_text    = $('#cashback-text');
    var premium_value    = $('.premium-value');

    // The Handle
    var $handle;

    // Update the slider value
    function updateHandle(el, val) {
        el.textContent = val;
    }

    // Set the bar width
    function setBars() {

        premium_value.each(function() {

            var price     = ($(this).text().replace(/[^\d\.]/g, ''));
            var bar_width = ((price / yearly_high) * 100);
            var bar       = $(this).closest('td').prev().find('.bar div');

            bar.css({
                width: bar_width+'%'
            });

        });
    }

    // Round to two decimal places
    function roundToTwo(num) {
        return +(Math.round(num + "e+2")  + "e-2");
    }

    // TODO - Unite functions below into one
    // function flipReset(el, feature) {

    //     $(el).click(function(e) {

    //         e.preventDefault();

    //         // Loading Overlay
    //         $('#loading-overlay-switch').fadeIn('400', function() {

    //         });

    //         // Reset - if neeeded

    //     });

    // }

    // What if - Needs improving
    $('#what-if').click(function(e) {

        e.preventDefault();

        // Set field for mixpanel
        $('#buy_form_claim_used').val(true);

        $('#loading-overlay-switch').fadeIn(function() {

            $('.premium-table').fadeToggle('400', function() {
                $('.claim-options').fadeIn();
                $('.loading-overlay').fadeOut();
                // $(document).scrollTop( $('#cashback-card').offset().top - 30);
            });

        });
    });

    $('#what-if-return').click(function(e) {

        e.preventDefault();

        $('#loading-overlay-switch').fadeIn(function() {

            $('.claim-options').fadeToggle('400', function() {
                $('.premium-table').fadeIn();
                $('.loading-overlay').fadeOut();
                // $(document).scrollTop( $('#cashback-card').offset().top - 30);

                // Reset the claims
                cashback_new.text('');
                cashback.removeClass('cashback-with-claim');
                var option = 0;
                $('.claim-options-list > button.active').removeClass('active');
                $('.claim-options-list > button:first-child').addClass('active');
                cashback_text.text('Cashback if no one claims');

            });
        });
    });

    function whatIf(save_value) {

        $('.list-group-item').each(function() {

            // Get data from element
            var option     = $(this).data('claim-option');
            var new_text   = $(this).data('cashback-text');

            $(this).click(function() {
                $(this).addClass('active').siblings().removeClass('active');
                $(cashback_text).text(new_text);
                switchIf();
            });

            // Trigger for slider
            if ($(this).hasClass('active')) {
                switchIf();
            }

            function switchIf() {

                switch(option) {
                    case 0: {
                        cashback_new.text('');
                        cashback.removeClass('cashback-with-claim');
                        break;
                    }
                    case 1: {
                        cashback_new.text('£0');
                        cashback.addClass('cashback-with-claim');
                        break;
                    }
                    case 2: {
                        if (save_value >= 40) {
                            cashback_new.text('£10');
                        } else {
                            cashback_new.text('£0');
                        }
                        cashback.addClass('cashback-with-claim');
                        break;
                    }
                    case 3: {
                        cashback.addClass('cashback-with-claim');
                        cashback_new.text('£0');
                        break;
                    }
                }
            }

        });
    }

    // Begin Range Slider
    $element

        .rangeslider({

            'update': true,

            polyfill: false,

            rangeClass: 'rewardslider',
            disabledClass: 'rewardslider--disabled',
            horizontalClass: 'rewardslider--horizontal',
            verticalClass: 'rewardslider--vertical',
            fillClass: 'rewardslider__fill',
            handleClass: 'rewardslider__handle',

            onInit: function() {

                // Set the number on the slider handle based on pos
                $handle = $('.rewardslider__handle', this.$range);
                updateHandle($handle[0], this.value);

                $('#loading-overlay-init').fadeOut('slow', function() {

                    var save_value = yearly_high;
                    whatIf(save_value);
                    setBars();

                });
            },

            onSlide: function(position, value) {

                // Set field for mixpanel
                $('#buy_form_slider_used').val(true);

                $('.slide-me').fadeOut();

                updateHandle($handle[0], this.value);

                var updated_value = $('#reward-slider').val();
                var save_value    = updated_value * connection_value;

                if (save_value > maxpot_value) {
                    save_value = maxpot_value;
                }

                var premium = roundToTwo(montly_premium - save_value);
                var total   = premium.toFixed(2);

                // Update cashback and premium
                function updateValues() {
                    $('#cashback').text('£' + save_value);
                    $('#premium').text('£' + total);
                }

                whatIf(save_value);
                updateValues();
                setBars();
            },

            onSlideEnd: function(position, value) {}
        })
});

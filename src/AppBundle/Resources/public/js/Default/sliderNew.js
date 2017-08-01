$(function(){

    // Expand The Range element for customisation
    $element = $('input[type="range"]');

    // Round to two decimal places
    function roundToTwo(num) {
        return +(Math.round(num + "e+2")  + "e-2");
    }

    // Slider Vars
    var current_value    = $('#reward-slider').val();
    var max_value        = $('#reward-slider').prop('max');
    var maxpot_value     = $('#reward-slider').data('maxpot');
    var connection_value = $('#reward-slider').data('connection-value');
    var annual_premium   = $('#reward-slider').data('premium');
    var montly_premium   = $('#reward-slider').data('monthly-premium');

    // Log for debugging
    // console.log(
    //     'Useful info: \n \n' +
    //     'Current Value = ' + current_value + ', \n' +
    //     'Max Value = ' + max_value + ', \n' +
    //     'Maxpot Value = £' + maxpot_value + ', \n' +
    //     'Connection Value = £' + connection_value + ', \n' +
    //     'Monthly Premium = £' + roundToTwo(montly_premium) + '\n \n');

    // The Handle
    var $handle;

    // The bubble
    var bubble = $('.rewardslider__output');

    // Update the slider value
    function updateHandle(el, val) {
        el.textContent = val;
    }

    // Update the bubble pos
    function updateBubble(pos, value, context) {
        pos = pos || context.position;
        var $bubble = $('.rewardslider__output', context.$range);
        var temPosition = pos + context.grabPos;
        var position = (temPosition <= context.handleDimension) ? context.handleDimension : (temPosition >= context.maxHandlePos) ? context.maxHandlePos : temPosition;

        if ($bubble.length) {
            var positionLeft = Math.ceil(position);
            var adjustedPos  = positionLeft - 55 + 'px';

            if (position == 40) {
                $bubble[0].style.left = '-35px';
            } else if (position == context.maxHandlePos) {
                $bubble[0].style.left = context.maxHandlePos - 35 + 'px';
            } else {
                $bubble[0].style.left = adjustedPos;
            }
        }
    }

    // Begin range slider
    $element.rangeslider({

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

            this.$range.append($(bubble));
            // this.$handle.append($(bubble));
            updateBubble(null, null, this);

            // Fade out the loading overlay
            $('#loading-overlay-init').fadeOut('slow');
            $(bubble).fadeIn();

        },

        onSlide: function(pos, value) {

            // Update the friend total
            updateHandle($handle[0], this.value);

            var updated_value = $('#reward-slider').val();
            var save_value = updated_value * connection_value;

            if (save_value > maxpot_value) {
                save_value = maxpot_value;
            }

            // Work out yearly then monthly discounted
            var premium   = annual_premium - save_value;
            var monthly   = roundToTwo(premium / 12);
            var effective = monthly.toFixed(2);

            updateBubble(pos, null, this);

            // Update
            $('#premium').text('£' + effective);

        },

        onSlideEnd: function(pos, value) {}
    });

});

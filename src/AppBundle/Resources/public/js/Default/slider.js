$(function(){

    const $element = $('input[type="range"]');

    const claimStates = [

        {
            name: 'no',
            text: 'Cashback when no one claims',
        },
        {
            name: 'me',
            text: 'Cashback when I claim',
        },
        {
            name: 'one',
            text: 'Cashback when 1 friend claims',
        },
        {
            name: 'two',
            text: 'Cashback when 2 friends claim',
        }

    ];

    var currentState;
    var $handle;

    $element

    .rangeslider({

        polyfill: false,

        rangeClass: 'rewardslider',
        disabledClass: 'rewardslider--disabled',
        horizontalClass: 'rewardslider--horizontal',
        verticalClass: 'rewardslider--vertical',
        fillClass: 'rewardslider__fill',
        handleClass: 'rewardslider__handle',


        onInit: function() {
            $handle = $('.rewardslider__handle', this.$range);
            updateHandle($handle[0], this.value);           
        },

        onSlide: function(position, value) {

            var current_value    = $('#reward-slider').val();
            var max_value        = $('#reward-slider').prop('max');
            var maxpot_value     = $('#reward-slider').data('slider-maxpot');
            var initial_value    = $('#reward-slider').prop('value');
            var connection_value = $('#reward-slider').data('slider-connection-value');

            // console.log(current_value, max_value, maxpot_value, initial_value, connection_value);
        

            var setCashback = function() {
                var save_value = current_value * connection_value;

                if (save_value > maxpot_value) {
                    save_value = maxpot_value;
                }

                $('#cashback').text('£' + save_value);
            }

            var updateValue = function() {
                setCashback();
            }

            setCashback();

        },


        onSlideEnd: function(position, value) {}

    })

    .on('input', function() {
        updateHandle($handle[0], this.value);   
    });

    function updateHandle(el, val) {
        el.textContent = val;
    }    

    function roundToTwo(num) {
        return +(Math.round(num + "e+2")  + "e-2");
    }

    // var setConnectionText = function() {
    //     var save_value = current_value * connection_value;

    //     // console.log(save_value);

    //     if (save_value > maxpot_value) {
    //         save_value = maxpot_value;
    //     }

    //     var potential_value = roundToTwo(maxpot_value - save_value);

    //     $('#money_back').text('£' + save_value); 
    // }    

    // var updateValue = function() {
    //     setConnectionText();;
    // }    

    // setConnectionText();   


});

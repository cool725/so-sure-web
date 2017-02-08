$(function(){

    // Expand The Range element for customisation
    const $element = $('input[type="range"]');

    // Slider Vars
    var current_value    = $('#reward-slider').val();
    var max_value        = $('#reward-slider').prop('max');
    var maxpot_value     = $('#reward-slider').data('maxpot');
    var connection_value = $('#reward-slider').data('connection-value');
    var montly_premium   = $('#reward-slider').data('premium');    

    // Get data fromtable - Needs to be done better
    var premium_value  = $('.premium-value');      
    var all_premiums   = [];

    // Store Values from table
    $(premium_value).each(function() {
        all_premiums.push($(this).text().replace(/[^\d\.]/g, ''));
    });

    // Sort in descending order
    all_premiums.sort(function(a,b) {
        return b - a;
    });  

    // We use the highest as the 100% value 
    $high = all_premiums[0];     

    // var currentState;
    var $handle;

    // Update the slider value
    function updateHandle(el, val) {
        el.textContent = val;
    }      

    // Set the bar width
    function setBars() {

        premium_value.each(function() {
            
            var price     = ($(this).text().replace(/[^\d\.]/g, ''));
            var bar_width = ((price / $high) * 100);
            var bar       = $(this).closest('td').prev().find('.bar div');

            bar.animate({
                width: bar_width+'%',
                },
                800, function() {
            });
        });
    }

    function roundToTwo(num) {
        return +(Math.round(num + "e+2")  + "e-2");
    }

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

                // Set the number on the slider handle based on pos
                $handle = $('.rewardslider__handle', this.$range);
                updateHandle($handle[0], this.value);  

                setBars();    
            },

            onSlide: function(position, value) {

                updateHandle($handle[0], this.value);   

                var updated_value = $('#reward-slider').val();
                var save_value    = updated_value * connection_value;

                if (save_value > maxpot_value) {
                    save_value = maxpot_value;
                }

                var premium = roundToTwo(montly_premium - save_value);


                // Update cashback and premium
                function updateValues() {
                    $('#cashback').text('£' + save_value);
                    $('#premium').text('£' + premium);
                    setBars();                    
                }                

                updateValues();
            },

            onSlideEnd: function(position, value) {}

        })

});
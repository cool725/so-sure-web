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

    // Slider Vars
    var current_value    = $('#reward-slider').val();
    var max_value        = $('#reward-slider').prop('max');
    var maxpot_value     = $('#reward-slider').data('slider-maxpot');
    var connection_value = $('#reward-slider').data('slider-connection-value');
    var montly_premium   = $('#reward-slider').data('slider-premium');    

    // Table Var
    var premiumVal  = $('.premium-value');      

    var allPremiums = [];

    $(premiumVal).each(function() {
        allPremiums.push($(this).text().replace(/[^\d\.]/g, ''));
    });

    allPremiums.sort(function(a,b) {
        return b - a;
    });  

    $high = allPremiums[0];     

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

            var setPremium = function() {
                var save_value = current_value * connection_value; 

                if (save_value > maxpot_value) {
                    save_value = maxpot_value;
                }

                var premium = montly_premium - save_value;

                $('#premium').text('£' + premium.toFixed(2));
            }     

            setPremium();

            $(premiumVal).each(function() {

                var price = ($(this).text().replace(/[^\d\.]/g, ''));
                var width = ((price / $high) * 100);
                var bar   = $(this).closest('td').prev().find('.bar div');
                
                bar.css('width',width+'%');

            });

        },

        onSlide: function(position, value) {

            // Slider Vars - Get updated value 
            var current_value    = $('#reward-slider').val();
            var max_value        = $('#reward-slider').prop('max');
            var maxpot_value     = $('#reward-slider').data('slider-maxpot');
            var connection_value = $('#reward-slider').data('slider-connection-value');
            var montly_premium   = $('#reward-slider').data('slider-premium');

            var setCashback = function() {
                var save_value = current_value * connection_value;

                if (save_value > maxpot_value) {
                    save_value = maxpot_value;
                }

                $('#cashback').text('£' + save_value.toFixed(2));
            }

            var setPremium = function() {
                if (save_value > maxpot_value) {
                    save_value = maxpot_value;
                }

                var save_value = current_value * connection_value; 
                var premium = montly_premium - save_value;

                $('#premium').text('£' + premium.toFixed(2));
 
                // Re-Set the bar width for us
                var width = ((premium / $high) * 100);
                console.log(width);
                $('.bar-us div').css('width',width+'%');                
            }    

            setCashback();
            setPremium();
        },


        onSlideEnd: function(position, value) {}

    })

    .on('input', function() {
        updateHandle($handle[0], this.value);   
    });

    // Update the slider value
    function updateHandle(el, val) {
        el.textContent = val;
    }    

});
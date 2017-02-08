$(function(){

    // Expand The Range element for customisation
    const $element = $('input[type="range"]');

    // Slider Vars
    var current_value    = $('#reward-slider').val();
    var max_value        = $('#reward-slider').prop('max');
    var maxpot_value     = $('#reward-slider').data('maxpot');
    var connection_value = $('#reward-slider').data('connection-value');
    var montly_premium   = $('#reward-slider').data('premium');    
    var claim_check      = $('#claim-check').data('cashback-check');
    var cashback         = $('#cashback');
    var cashback_new     = $('#cashback-with-claim');
    var save_value       = '';       

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
                opacity: 1,
                },
                900, function() {
            });
        });
    }

    // Round to two decimal places
    function roundToTwo(num) {
        return +(Math.round(num + "e+2")  + "e-2");
    }
     
         
    // function whatIf() {

    //     // What if
    //     $('#what-if').click(function(e) {
    //         e.preventDefault();
    //         $('.loading-overlay').fadeIn(function() {
    //             $('.premium-table, .claim-options').fadeToggle('400', function() {
    //                 $('.loading-overlay').fadeOut();

    //             });
    //         });
    //     });                

    //     $('.claim-options input[type="radio"]').each(function() {

    //         var claim_text = $(this).data('cashback-text');
    //         var claim_targ = $(this).data('cashback-target');
    //         var claim_opti = $(this).data('claim-option');

    //         $(this).change(function() {
    //             $(claim_targ).text(claim_text);

    //             switch(claim_opti) {
    //                 case 'nobody': {
    //                     cashback_new.text('');
    //                     cashback.removeClass('cashback-with-claim');
    //                     break;
    //                 }
    //                 case 'iclaim': {
    //                     cashback_new.text('£0');
    //                     cashback.addClass('cashback-with-claim');
    //                     break;
    //                 }
    //                 case '1claim': {
    //                     cashback_new.text('£0');
    //                     cashback.addClass('cashback-with-claim');
    //                     break;
    //                 }
    //                 case '2claims': {
    //                     cashback_new.text('£0');
    //                     cashback.addClass('cashback-with-claim');
    //                     break;
    //                 }                                                            
    //             }
    //         });

    //     });
    // }

    // Begin Range Slider
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
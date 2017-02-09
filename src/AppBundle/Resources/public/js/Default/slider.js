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
                easing: 'linear',
                },
                300, function() {
            });

            bar.css({
                width: bar_width+'%'
            });
        });
    }

    // Round to two decimal places
    function roundToTwo(num) {
        return +(Math.round(num + "e+2")  + "e-2");
    }

    // What if
    $('#what-if').click(function(e) {
        e.preventDefault();       

        $('.loading-overlay').fadeIn(function() {
            $('.premium-table, .claim-options').fadeToggle('400', function() {
                $('.loading-overlay').fadeOut();
                $(document).scrollTop( $('#cashback-card').offset().top - 30);  
            });
        });
    });   

    $('#what-if-return').click(function(e) {
        e.preventDefault();       

        $('.loading-overlay').fadeIn(function() {
            $('.premium-table, .claim-options').fadeToggle('400', function() {
                $('.loading-overlay').fadeOut();
                $(document).scrollTop( $('#cashback-card').offset().top - 30);  
                cashback_new.text('');
                cashback.removeClass('cashback-with-claim');  
                var option = 0;              
            });
        });
    });       

    function whatIf(save_value) {

        $('.list-group-item').each(function() {

            // Get data from element
            var option = $(this).data('claim-option');
            var new_text   = $(this).data('cashback-text');
            var target = $(this).parent().data('cashback-target');

            $(this).click(function() {
                $(this).toggleClass('active').siblings().removeClass('active');
                $(target).text(new_text);
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

                var save_value = $high;
                whatIf(save_value);
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
                    $('#cashback').text('£' + save_value.toFixed(2));
                    $('#premium').text('£' + premium.toFixed(2));                  
                }               

                whatIf(save_value);                             
                updateValues();
            },

            onSlideEnd: function(position, value) {
                setBars();                  
            }

        })

});
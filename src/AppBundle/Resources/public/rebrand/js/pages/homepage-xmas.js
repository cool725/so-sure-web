// homepage-xmas.js

require('../../sass/pages/homepage-xmas.scss');

// Require BS component(s)

// Require components

$(function(){

    let snowIntensity = 400; // smaller number = more snowflakes;
    let snowType = '*';

    function snowFlake(){
        let snowflake = this;

        snowflake.x = (Math.random() * $(document).width());
        snowflake.size = (Math.random() * 35) + 10;
        snowflake.opacity = Math.random();
        snowflake.body = $('<span class="snowflake">' + snowType + '</span>');

        snowflake.body.css({
            'font-size': this.size + 'px',
            'left': this.x +'px',
            'opacity': this.opacity
        });

        snowflake.fall = function(){
            let that = this;
            let $snowflake = this.body;
            let swingDirection = 1;
            let swingWave = Math.random() * 100;
            let interval = setInterval(function(){
                $snowflake.css({left: that.x + (swingDirection * swingWave)});
                swingDirection = - swingDirection;
            }, 1000);
            let speed = (Math.random() * 3000) + 3000;

            $snowflake.animate({top: '100vh'}, speed, function(){
                clearInterval(interval);
                $snowflake.remove();
            });
        }

        $('body').append(snowflake.body);
        snowflake.fall();
    }

    let snow = window.setInterval(function () {
        new snowFlake();
    }, snowIntensity);

    $(document).on('keyup', function(e) {

        window.clearInterval(snow);

        if(e.keyCode == 79){

            window.setInterval(function () {
                snowIntensity = 700;
                snowType = '🍆';
                new snowFlake();
            }, snowIntensity);

        }
    });

});

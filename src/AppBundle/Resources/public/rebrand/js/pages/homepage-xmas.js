// homepage-xmas.js

require('../../sass/pages/homepage-xmas.scss');

// Require BS component(s)

// Require components

$(function(){

    let snowIntensity = 600; // smaller number = more snowflakes;
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
            }, 2000);
            let speed = (Math.random() * 3000) + 3000;

            $snowflake.animate({top: '100vh'}, speed, function(){
                clearInterval(interval);
                $snowflake.remove();
            });
        }

        $('.snow').append(snowflake.body);
        snowflake.fall();
    }

    let snow = window.setInterval(function () {
        new snowFlake();
    }, snowIntensity);

    $(document).on('keyup', function(e) {

        let egg,
            phone;

        window.clearInterval(snow, egg, phone);

        if(e.keyCode == 79){

            let egg = window.setInterval(function () {
                snowIntensity = 4000;
                snowType = '🍆';
                new snowFlake();
            }, snowIntensity);

        } else if(e.keyCode == 80) {

            let phone = window.setInterval(function () {
                snowIntensity = 4000;
                snowType = '📱';
                new snowFlake();
            }, snowIntensity);

        } else if(e.keyCode == 81) {

            $('.snow').fadeOut();

        }
    });



});

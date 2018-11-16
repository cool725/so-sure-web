// homepage-xmas.js

require('../../sass/pages/homepage-xmas.scss');

// Require BS component(s)

// Require components

// $(function(){


    // let snowIntensity = 400; // smaller number = more snowflakes;
    // let snowType = '*';

    // function snowFlake(){
    //     let snowflake = this;

    //     snowflake.x = (Math.random() * $(document).width());
    //     snowflake.size = (Math.random() * 35) + 10;
    //     snowflake.opacity = Math.random();
    //     snowflake.body = $('<span class="snowflake">' + snowType + '</span>');

    //     snowflake.body.css({
    //         'font-size': this.size + 'px',
    //         'left': this.x +'px',
    //         'opacity': this.opacity
    //     });

    //     snowflake.fall = function(){
    //         let that = this;
    //         let $snowflake = this.body;
    //         let swingDirection = 1;
    //         let swingWave = Math.random() * 100;
    //         let interval = setInterval(function(){
    //             $snowflake.css({left: that.x + (swingDirection * swingWave)});
    //             swingDirection = - swingDirection;
    //         }, 1000);
    //         let speed = (Math.random() * 3000) + 3000;

    //         $snowflake.animate({top: '100vh'}, speed, function(){
    //             clearInterval(interval);
    //             $snowflake.remove();
    //         });
    //     }

    //     $('body').append(snowflake.body);
    //     snowflake.fall();
    // }

    // let snow = window.setInterval(function () {
    //     new snowFlake();
    // }, snowIntensity);

    // $(document).on('keyup', function(e) {

    //     // if(e.keyCode == 79){

    //         window.clearInterval(snow);

    //     //     window.setInterval(function () {
    //     //         snowIntensity = 2000;
    //     //         snowType = '🍆';
    //     //         new snowFlake();
    //     //     }, snowIntensity);
    //     // }
    // });
    //
(function() {
  var snowflakes = [],
      moveAngle = 0,
      animationInterval;

  /**
   * Generates a random number between the min and max (inclusive).
   * @method getRandomNumber
   * @param {Number} min
   * @param {Number} max
   * @return {Number}
   */
  function getRandomNumber(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
  }

  /**
   * Creates a new snowflake div and returns it.
   * @method createSnowflake
   * @return {HTMLElement}
   */
  function createSnowflake() {
    var el = document.createElement('div'),
        style = el.style;

    style.borderRadius = '100%';
    style.border = getRandomNumber(1, 4) + 'px solid white';
    style.position = 'fixed';
    style.zIndex = '999999';
    style.boxShadow = '0 0 2px rgba(255,255,255,0.8)';
    style.top = getRandomNumber(0, window.innerHeight) + 'px';
    style.left = getRandomNumber(0, window.innerWidth) + 'px';

    return el;

    return el;
  }

  /**
   * Calls the moveSnowflake method for each of the snowflakes in the cache.
   * @method moveSnowflakes
   * @return {Void}
   */
  function moveSnowflakes() {
    var l = snowflakes.length,
        i;

    moveAngle += 0.01;

    for (i=0; i<l; i++) {
      moveSnowflake(snowflakes[i]);
    }
  }

  /**
   * Moves an individual snowflake element using some simple math.
   * @method moveSnowflake
   * @param {HTMLElement} el
   * @return {Void}
   */
  function moveSnowflake(el) {
    var style = el.style,
        height = window.innerHeight,
        radius,
        top;

    radius = parseInt(style.border, 10);

    top = parseInt(style.top, 10);
    top += Math.cos(moveAngle) + 1 + radius/2;

    if (top > height) {
      resetSnowflake(el);
    } else {
      style.top = top + 'px';
    }
  }

  /**
   * Puts the snowflake back at the top in a random horizontal start position.
   * @method resetSnowflake
   * @param {HTMLElement} el
   * @return {Void}
   */
  function resetSnowflake(el) {
    var style = el.style;

    style.top = '0px';
    style.left = getRandomNumber(0, window.innerWidth) + 'px';
  }

  /**
   * The kick-off method. Asks how many snowflakes to make and then makes them!
   * @method setup
   * @return {Void}
   */
  function setup() {
   var number = prompt('How many snowflakes would you like?'),
        particle,
        i;

    // Setup snow particles
    for (i=0; i< number ; i++) {
      particle = snowflakes[i] = createSnowflake();
      document.body.appendChild(particle);
    }

    // Set animation intervals
    animationInterval = setInterval(moveSnowflakes, 33);
  }

  setup();
}());

// });

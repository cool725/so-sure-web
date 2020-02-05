// user-dashboard.js

require('../../../sass/pages/user/user-dashboard.scss');

// Require BS component(s)

// Require components
let ProgressBar = require('progressbar.js');

import tracking from '../../common/track-data.js';

$(function() {

    // Reward pot dial
    let rewardPotDial = $('#reward_pot_dial'),
        total = Math.round((rewardPotDial.data('pot-value') / rewardPotDial.data('max-pot')) * 100) / 100;

    let rewardPotDialCreate = new ProgressBar.Circle('#reward_pot_dial', {
        strokeWidth: 4,
        easing: 'easeInOut',
        duration: 1400,
        color: '#ffffff',
        trailColor: '#99e3ff',
        trailWidth: 1,
        svgStyle: null
    });

    rewardPotDialCreate.animate(total);

    $('.dashboard-dial-content').on('click', function(e) {
        e.preventDefault();

        $('.dashboard-dial-content').toggleClass('hideme');
    });

    // Connection bonus dial
    let connectionBonusDial = $('#connection_bonus_dial'),
        bonus = Math.round((connectionBonusDial.data('bonus-days-remaining') / connectionBonusDial.data('bonus-days-total')) * 100) / 100;

    let connectionBonusDialCreate = new ProgressBar.Circle('#connection_bonus_dial', {
        strokeWidth: 4,
        easing: 'easeInOut',
        duration: 1400,
        color: '#00BAFF',
        trailWidth: 0,
        svgStyle: null
    });

    connectionBonusDialCreate.animate(bonus);
});

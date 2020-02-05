// set-agent.js

let usrAgnt = null;
let navi = navigator.userAgent.toLowerCase();

//TODO: Add a more specific detection here including device
if (navi.match(/(iphone|ipod|ipad)/)) {
    usrAgnt = 'iOS';
}

export default usrAgnt;

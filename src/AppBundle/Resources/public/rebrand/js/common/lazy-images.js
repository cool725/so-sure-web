// lazy-images.js

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

function init() {
    let imgDefer = document.getElementsByTagName('img');
    for (var i=0; i<imgDefer.length; i++) {
        if(imgDefer[i].getAttribute('data-src')) {
            imgDefer[i].setAttribute('src',imgDefer[i].getAttribute('data-src'));
    } } }
window.onload = init;

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

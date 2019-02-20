// landing.js

require('../../sass/pages/landing.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');

// Require components
require('../components/table.js');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

// fixed-width.js

const fixedWidth = () => {
    let parentWidth = $('.fixed-width').parent().width();
    $('.fixed-width').width(parentWidth);
}

$(window).on('load', function() {
    fixedWidth();
});

$(window).on('resize', function() {
    fixedWidth();
});

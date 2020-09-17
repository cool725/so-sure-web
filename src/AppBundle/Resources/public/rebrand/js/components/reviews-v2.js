// table.js

$(function() {

    const prev   = $('.reviews-mobile-controls-prev'),
          next   = $('.reviews-mobile-controls-next'),
          review = $('.reviews-mobile-container .reviews-mobile-item');
    let   count = 0;


    let showContent = (n, direction) => {
        if (count > 0) {
          prev.removeClass('disabled');
        } else {
          prev.addClass('disabled');
        }
        if (count == 3) {
          next.addClass('disabled');
        } else {
          next.removeClass('disabled');
        }
        n.addClass('active ' + direction)
        .siblings().removeClass('active ' + direction);
    }

    prev.on('click', function(e) {
        e.preventDefault();
        count--;
        let reviewItem = review.filter('.active').prev();
        showContent(reviewItem, 'fade-out');
    });

    next.on('click', function(e) {
        e.preventDefault();
        count++;
        let reviewItem = review.filter('.active').next();
        showContent(reviewItem, 'fade-out');
    });
});

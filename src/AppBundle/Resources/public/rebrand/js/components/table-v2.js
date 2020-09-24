// table.js

$(function() {

    const prev = $('.table-list-controls-prev'),
          next = $('.table-list-controls-next'),
          cell = $('.td-collection .swap');
    let   count = 0;


    let showContent = (n, direction) => {
        if (count > 0) {
          prev.removeClass('disabled');
        } else {
          prev.addClass('disabled');
        }
        if (count == 6) {
          next.addClass('disabled');
        } else {
          next.removeClass('disabled');
        }
        n.addClass('active ' + direction)
        .siblings().removeClass('active fade-left fade-right');
    }

    prev.on('click', function(e) {
        e.preventDefault();
        count--;
        let prevCell = cell.filter('.active').prev();
        showContent(prevCell, 'fade-left');
    });

    next.on('click', function(e) {
        e.preventDefault();
        count++;
        let nextCell = cell.filter('.active').next();
        showContent(nextCell, 'fade-right');
    });
});

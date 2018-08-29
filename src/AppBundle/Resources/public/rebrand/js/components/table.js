// table.js

$(function() {

    const prev = $('.td-prev-btn'),
          next = $('.td-next-btn'),
          cell = $('.td-container .td-swap');


    let showContent = (n) => {
        n.addClass('td-active')
        .siblings().removeClass('td-active');
    }

    prev.on('click', function(e) {
        e.preventDefault();

        let prevCell = cell.filter('.td-active').prev();

        showContent(prevCell);
    });

    next.on('click', function(e) {
        e.preventDefault();

        let nextCell = cell.filter('.td-active').next();

        showContent(nextCell);
    });
});

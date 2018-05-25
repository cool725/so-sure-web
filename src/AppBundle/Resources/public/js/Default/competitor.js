$(function(){

    var table = $('.table');
    var fixed = table.clone().insertBefore(table).addClass('fixed-column');

    fixed.find('th:not(:first-child),td:not(:first-child)').remove();

    fixed.find('tr').each(function (i, elem) {
        $(this).height(table.find('tr:eq(' + i + ')').height());
    });

    $('.comparison__controls').click(function(e) {
        $('#carousel-comparison').carousel('pause');
    });

});

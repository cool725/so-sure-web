// toggle-text.js
//
// TODO: Move or use new data method
$.fn.extend({
    toggleText: function(a, b){
        return this.text(this.text() == b ? a : b);
    }
});

$('.toggle-text[data-toggle="collapse"]').on('click', function(e){
    e.preventDefault();

    $(this)
    .data('text-original', $(this).html())
    .html($(this).data('text-swap') )
    .data('text-swap', $(this).data('text-original'));

});

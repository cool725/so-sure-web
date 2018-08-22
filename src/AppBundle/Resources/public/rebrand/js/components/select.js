// select.js

(function($, window){

    $.fn.resizeselect = function(settings) {
        return this.each(function(){

            $(this).change(function(e) {

                let $this = $(this);

                // Create test element
                let text = $this.find('option:selected').text();
                let $test = $('<span>').html(text).css({
                    'font-size': $this.css('font-size'),
                    'visibility': 'hidden'
                });

                // Append to get width
                $test.appendTo($this.parent());
                let width = $test.width();
                $test.remove();

                // Set the width
                $this.width(width + 10);

            }).change();

        });
    };

    $("select.resizeselect").resizeselect();

})(jQuery, window);

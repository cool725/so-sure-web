// custom-select.js

(function($, window){

    $.fn.customSelect = function(settings) {
        return this.each(function(){

            let $this = $(this),
                numberOfOptions = $(this).children('option').length;

            $this.addClass('select-hidden d-none');
            $this.wrap('<div class="select"></div>');
            $this.after('<div class="select-styled"></div>');

            let $styledSelect = $this.next('div.select-styled');
            $styledSelect.text($this.children('option').eq(0).text());

            let $list = $('<ul />', {'class': 'select-options'}).insertAfter($styledSelect);

            for (var i = 0; i < numberOfOptions; i++) {
                $('<li />', {
                    text: $this.children('option').eq(i).text(),
                    rel: $this.children('option').eq(i).val()
                }).appendTo($list);
            }

            let $listItems = $list.children('li');

            $styledSelect.on('click', function(e) {
                e.stopPropagation();

                $('div.select-styled.active').not(this).each(function() {
                    $(this).removeClass('active').next('ul.select-options').hide();
                });

                $(this).toggleClass('active').next('ul.select-options').toggle();
            });

            $listItems.on('click', function(e) {
                e.stopPropagation();
                $styledSelect.text($(this).text()).removeClass('active');
                $this.val($(this).attr('rel'));
                $list.hide();
                //console.log($this.val());
            });

            $(document).on('click', function(e) {
                $styledSelect.removeClass('active');
                $list.hide();
            });

        });
    };

    $('select.custom-select').customSelect();

})(jQuery, window);

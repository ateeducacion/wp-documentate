/* global jQuery */
(function ($) {
  function initTitleTextarea() {
    if (!$('body').hasClass('post-type-documentate_document')) return;

    var $title = $('#title');
    if (!$title.length) return;
    if ($('#documentate_title_textarea').length) return; // already enhanced

    var current = $title.val();
    var placeholder = $title.attr('placeholder') || '';

    var $ta = $('<textarea/>', {
      id: 'documentate_title_textarea',
      class: 'widefat',
      rows: 4,
      placeholder: placeholder
    }).val(current);

    var $wrap = $('#titlewrap');
    $title.hide().attr('aria-hidden', 'true');
    $ta.insertAfter($title);

    // Sync textarea -> hidden title input continuously
    $ta.on('input', function () {
      $title.val($ta.val());
    });

    // Ensure sync on form submit as well
    $('#post').on('submit', function () {
      $title.val($ta.val());
    });
  }

  $(initTitleTextarea);
})(jQuery);


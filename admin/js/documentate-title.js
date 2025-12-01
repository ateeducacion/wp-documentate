/* global jQuery, documentateTitleConfig */
(function ($) {
  function initTitleTextarea() {
    if (!$('body').hasClass('post-type-documentate_document')) return;

    var $title = $('#title');
    if (!$title.length) return;
    if ($('#documentate_title_textarea').length) return; // already enhanced

    var current = $title.val();
    var placeholder = (typeof documentateTitleConfig !== 'undefined' && documentateTitleConfig.placeholder)
      ? documentateTitleConfig.placeholder
      : ($title.attr('placeholder') || '');

    var $ta = $('<textarea/>', {
      id: 'documentate_title_textarea',
      class: 'widefat',
      rows: 4,
      placeholder: placeholder,
      required: true
    }).val(current);

    var $wrap = $('#titlewrap');
    $title.hide().attr('aria-hidden', 'true');
    $ta.insertAfter($title);

    // Uppercase checkbox
    var uppercaseLabel = (typeof documentateTitleConfig !== 'undefined' && documentateTitleConfig.uppercaseLabel)
      ? documentateTitleConfig.uppercaseLabel
      : 'Uppercase title in document';
    var uppercaseHint = (typeof documentateTitleConfig !== 'undefined' && documentateTitleConfig.uppercaseHint)
      ? documentateTitleConfig.uppercaseHint
      : 'The title will be rendered in uppercase in the generated document.';

    // Get initial value from config (set by PHP from post meta)
    var uppercaseDefault = (typeof documentateTitleConfig !== 'undefined' && documentateTitleConfig.uppercaseDefault)
      ? documentateTitleConfig.uppercaseDefault
      : '1';
    var isChecked = uppercaseDefault === '1';

    // Create hidden field for form submission (in the post form)
    var $hiddenUppercase = $('<input/>', {
      type: 'hidden',
      id: 'documentate_title_uppercase',
      name: 'documentate_title_uppercase',
      value: uppercaseDefault
    });
    // Insert hidden field in the form (near the title)
    $hiddenUppercase.insertAfter($title);

    var $checkboxWrap = $('<p/>', { class: 'documentate-title-uppercase-wrap' });
    var $checkbox = $('<input/>', {
      type: 'checkbox',
      id: 'documentate_title_uppercase_checkbox'
    }).prop('checked', isChecked);
    var $label = $('<label/>', { for: 'documentate_title_uppercase_checkbox' })
      .append($checkbox)
      .append(' ' + uppercaseLabel);

    $checkboxWrap.append($label);
    $checkboxWrap.append($('<p/>', { class: 'description', text: uppercaseHint }));
    $checkboxWrap.insertAfter($ta);

    // Sync checkbox -> hidden field
    $checkbox.on('change', function () {
      $hiddenUppercase.val($(this).is(':checked') ? '1' : '0');
    });

    // Error message element
    var errorMessage = (typeof documentateTitleConfig !== 'undefined' && documentateTitleConfig.requiredMessage)
      ? documentateTitleConfig.requiredMessage
      : 'Title is required';
    var $error = $('<p/>', {
      id: 'documentate_title_error',
      class: 'documentate-title-error',
      text: errorMessage
    }).hide();
    $error.insertAfter($checkboxWrap);

    // Sync textarea -> hidden title input continuously
    $ta.on('input', function () {
      $title.val($ta.val());
      // Clear error state on input
      if ($.trim($ta.val()) !== '') {
        $ta.removeClass('documentate-title-invalid');
        $error.hide();
      }
    });

    // Ensure sync on form submit as well
    $('#post').on('submit', function (e) {
      // Sync all TinyMCE editors to their textareas before submit
      if (typeof tinyMCE !== 'undefined') {
        tinyMCE.triggerSave();
      }
      $title.val($ta.val());

      // Validate title is not empty
      if ($.trim($ta.val()) === '') {
        e.preventDefault();
        $ta.addClass('documentate-title-invalid');
        $error.show();
        $ta.focus();
        // Scroll to title area
        $('html, body').animate({
          scrollTop: $ta.offset().top - 50
        }, 300);
        return false;
      }
    });
  }

  $(initTitleTextarea);
})(jQuery);


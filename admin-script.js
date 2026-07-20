jQuery(document).ready(function ($) {
    'use strict';

    // Apply order filters
    $('.wco-overdue-filter-toggle').on('change', function () {
        $(this).closest('form').trigger('submit');
    });

    // ── Column visibility toggles ─────────────────────────────────────────
    $('.usr-column-toggle').on('change', function () {
        var $checkbox  = $(this);
        var column     = $checkbox.data('column');
        var visible    = $checkbox.is(':checked');
        var colClass   = '.usr-col-' + column;

        // Show / hide all cells (th + td) for this column
        if (visible) {
            $(colClass).show();
        } else {
            $(colClass).hide();
        }

        // Persist preference via AJAX
        $.ajax({
            url:  usrData.ajax_url,
            type: 'POST',
            data: {
                action:  'wco_save_column_prefs',
                nonce:   usrData.nonce,
                column:  column,
                visible: visible,
            },
            success: function (response) {
                if (response.success) {
                    var $label = $checkbox.closest('label');
                    $label.addClass('usr-saved');
                    setTimeout(function () {
                        $label.removeClass('usr-saved');
                    }, 600);
                }
            },
        });
    });

    // ── Apply saved preferences on page load ─────────────────────────────
    if (typeof usrData.column_prefs !== 'undefined') {
        $.each(usrData.column_prefs, function (column, visible) {
            if (!visible) {
                $('.usr-col-' + column).hide();
            }
        });
    }
});

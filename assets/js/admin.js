/**
 * Bangla Track Admin Server Scripts
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Copy API endpoint to clipboard
        $('.bt-server-quick-actions code').on('click', function() {
            var text = $(this).text();
            navigator.clipboard.writeText(text).then(function() {
                alert('API endpoint copied to clipboard!');
            });
        });
    });

})(jQuery);

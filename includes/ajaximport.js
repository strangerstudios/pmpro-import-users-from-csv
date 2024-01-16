jQuery(document).ready(function () {
    //find status
    var $status = jQuery('#importstatus');
    var $count = 0;
    var $title = document.title;
    var $cycles = ['|', '/', '-', '\\'];
    var $pausebutton = jQuery('#pauseimport');
    var $resumebutton = jQuery('#resumeimport');
    var $pauseimport = false;

    //enable pause button	
    $pausebutton.click(function () {
        $pauseimport = true;
        $import_timer = false;
        $pausebutton.hide();
        $resumebutton.show();

        $status.html($status.html() + 'Pausing. You may see one more partial import update under here.\n');
    });

    //enable resume button
    $resumebutton.click(function () {
        $pauseimport = false;
        $resumebutton.hide();
        $pausebutton.show();

        $status.html($status.html() + 'Resuming...\n');

        ai_importPartial();
    });

    //start importing and update status
    if ($status.length > 0) {
        $status.html($status.html() + '\n' + 'JavaScript Loaded.\n');

        function ai_importPartial() {
            jQuery.ajax({
                url: ajaxurl, type: 'GET', timeout: 30000,
                dataType: 'html',
                data: 'action=pmpro_import_users_from_csv&filename=' + ai_filename + '&users_update=' + ai_users_update + '&new_user_notification=' + ai_new_user_notification,
                error: function (xml) {
                    alert('Error with import. Try refreshing.');
                    jQuery('#pmproiucsv_return_home').show();
                },
                success: function (responseHTML) {
                    if (responseHTML == 'error') {
                        alert('Error with import. Try refreshing.');
                        document.title = $title;
                        jQuery('#pmproiucsv_return_home').show();
                    }
                    else if (responseHTML == 'nofile') {
                        $status.html($status.html() + '\nCould not find the file ' + ai_filename + '. Maybe it has already been imported.');
                        document.title = $title;
                        jQuery('#pmproiucsv_return_home').show();
                    }
                    else if (responseHTML == 'done') {
                        $status.html($status.html() + '\nDone!');
                        document.title = '! ' + $title;
                        jQuery('#pmproiucsv_return_home').show();
                    }
                    else {
                        $status.html($status.html() + responseHTML);
                        document.title = $cycles[$count % 4] + ' ' + $title;
                        jQuery('#pmproiucsv_return_home').show();
                        if (!$pauseimport)
                            var $import_timer = setTimeout(function () { ai_importPartial(); }, 2000);
                    }

                    //scroll the text area unless the mouse is over it
                    if (jQuery('#importstatus:hover').length != 0) {
                        $status.scrollTop($status[0].scrollHeight - $status.height());
                    }
                }
            });
        }

        var $import_timer = setTimeout(function () { ai_importPartial(); }, 2000);
    }
});


jQuery(document).ready(function ($) {
    $('#import_users_csv').submit(function (event) {
        event.preventDefault();
        const fileInput = $('#users_csv')[0];
        const file = fileInput.files[0];

        if (file) {
            const reader = new FileReader();
            reader.onload = function (e) {
                const content = e.target.result;
                const rows = content.split('\n');

                // Check headers in the first row
                const headers = rows[0].split(/\W/g).filter(e => e.length > 0);
                const requiredHeaders = pmproiucsv.required_headers;

                // if sub_transaction_id is in headers, add other required headers like payment_method
                if (headers.includes('membership_subscription_transaction_id')) {
                    requiredHeaders.push('membership_gateway');
                }

                const missingHeaders = [];

                requiredHeaders.forEach(header => {
                    if (!headers.includes(header)) {
                        missingHeaders.push(header);
                    }
                });

                if (missingHeaders.length > 0) {
                    const confirmation = confirm('Warning missing headers: ' + missingHeaders.join(', ') + '\n\nYour import seems to be missing the required headers and might not import correctly (Ignore this message if you are certain about your CSV file)' + '\n\nDo you want to proceed?');
                    if (!confirmation) {
                        // Redirect to current URL and add ?import=cancelled in the URL.
                        const url = new URL(window.location.href);
                        url.searchParams.set('import', 'cancelled');
                        window.location.href = url;
                        return false;
                    } else {
                        // Headers are present, proceed with form submission
                        $('#import_users_csv').unbind('submit').submit();
                    }
                } else {
                    // Headers are present, proceed with form submission
                    $('#import_users_csv').unbind('submit').submit();
                }

            };

            reader.readAsText(file);
        }
    });
});
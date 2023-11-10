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

jQuery(document).ready(function($) {
    $('.henkan-tab-btn').on('click', function() {
        $('.henkan-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.henkan-tab-content').hide();
        $('#tab-' + $(this).data('target')).show();
    });

    let todoList = [];
    let totalTodo = 0;

    $('#henkan_start_scan').click(function(e) {
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true).text('Scanne...');
        
        $.post(henkan_ajax.ajax_url, {
            action: 'henkan_scan',
            nonce: henkan_ajax.nonce_scan,
            rescan_all: $('#henkan_bulk_rescan_all').is(':checked') ? 1 : 0,
            bulk_only_unconverted: $('#henkan_bulk_only_unconverted').is(':checked') ? 1 : 0
        }, function(res) {
            if(res.success) {
                if(res.data.debug_log && res.data.debug_log.length > 0) {
                    console.group("Henkan Scan Debug");
                    res.data.debug_log.forEach(l => console.log(l));
                    console.groupEnd();
                }

                todoList = res.data.items;
                totalTodo = todoList.length;
                $('#henkan_total_found').text(res.data.total_scanned);
                $('#henkan_to_convert').text(totalTodo);
                $('#henkan_scan_results').slideDown();
                btn.text('Scan abgeschlossen').prop('disabled', false);
                $('#henkan_log_list').prepend('<li>Scan abgeschlossen: ' + totalTodo + ' Dateien.</li>');
            }
        });
    });

    $('#henkan_start_convert').click(function(e) {
        e.preventDefault();
        $(this).hide();
        $('#henkan_progress_ui').slideDown();
        processNext();
    });

    function processNext() {
        if(todoList.length === 0) {
            $('#henkan_status_text').text(henkan_ajax.i18n.done);
            $('.fill').css('width', '100%');
            $('#henkan_log_list').prepend('<li><strong>Fertig!</strong> Alle Prozesse abgeschlossen.</li>');
            
            $.post(henkan_ajax.ajax_url, { action: 'henkan_clear_cache' }, function(res) {
                 if(res.success && res.data.msg) {
                     $('#henkan_log_list').prepend('<li style="color:#46b450"><strong>' + res.data.msg + '</strong></li>');
                 }
            });
            return;
        }

        let chunk = todoList.splice(0, 1); 
        let done = totalTodo - todoList.length;
        let pct = (done / totalTodo) * 100;
        
        $('.fill').css('width', pct + '%');
        $('#henkan_status_text').text(henkan_ajax.i18n.processing.replace('%1$s', done).replace('%2$s', totalTodo));

        $.post(henkan_ajax.ajax_url, {
            action: 'henkan_convert',
            nonce: henkan_ajax.nonce_convert,
            item: chunk[0]
        }, function(res) {
            if(res.success) {
                $('#henkan_log_list').prepend('<li>' + res.data.msg + '</li>');
            } else {
                $('#henkan_log_list').prepend('<li style="color:red">Fehler: ' + res.data.msg + '</li>');
            }
            processNext();
        }).fail(function() {
            $('#henkan_log_list').prepend('<li style="color:red">Netzwerkfehler</li>');
            processNext();
        });
    }
});

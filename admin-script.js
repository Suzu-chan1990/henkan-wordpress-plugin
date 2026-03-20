
jQuery(document).ready(function($) {
    const HENKAN_BULK_STATE = 'henkan_bulk_state_v1';
    function saveState(todo, total){ try{ localStorage.setItem(HENKAN_BULK_STATE, JSON.stringify({todo, total, ts: Date.now()})); }catch(e){} }
    function loadState(){ try{ const raw=localStorage.getItem(HENKAN_BULK_STATE); if(!raw) return null; const st=JSON.parse(raw); if(!st||!Array.isArray(st.todo)) return null; return st; }catch(e){ return null; } }
    function clearState(){ try{ localStorage.removeItem(HENKAN_BULK_STATE);}catch(e){} }
    const st0 = loadState();
    if(st0 && st0.todo.length>0){ $('#henkan_scan_results').show(); $('#henkan_to_convert').text(st0.todo.length); $('#henkan_resume_convert').show(); $('#henkan_status_text').text(henkan_ajax.i18n.ready_to_resume); }

    // Tabs Logic
    $('.henkan-tab-btn').on('click', function() {
        $('.henkan-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.henkan-tab-content').hide();
        $('#tab-' + $(this).data('target')).show();
    });

    // Media Library Button Handler
    $(document).on('click', '.henkan-quick-convert', function(e) {
        e.preventDefault();
        const btn = $(this);
        const spinner = btn.next('.henkan-spinner');
        const id = btn.data('id');

        btn.hide();
        spinner.addClass('is-active');

        $.post(henkan_ajax.ajax_url, {
            action: 'henkan_convert',
            nonce: henkan_ajax.nonce_convert,
            item: id
        }, function(res) {
            spinner.removeClass('is-active');
            if(res.success) {
                btn.parent().html('<span class="dashicons dashicons-yes" style="color:#46b450;"></span> <span style="color:#46b450; font-weight:bold;">OK</span>');
            } else {
                btn.show().text('Retry');
                alert(res.data.msg || 'Error');
            }
        }).fail(function() {
            spinner.removeClass('is-active');
            btn.show();
            alert('Server Error');
        });
    });

    // Bulk Process Logic
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
            bulk_only_unconverted: $('#henkan_bulk_only_unconverted').is(':checked') ? 1 : 0,
            bulk_only_failed: $('#henkan_bulk_only_failed').is(':checked') ? 1 : 0
        }, function(res) {
            if(res.success) {
                if(res.data.debug_log && res.data.debug_log.length > 0) {
                    console.group("Henkan Scan Debug");
                    res.data.debug_log.forEach(l => console.log(l));
                    console.groupEnd();
                }

                todoList = res.data.items;
                totalTodo = todoList.length;
                saveState(todoList, totalTodo);
                
                $('#henkan_total_found').text(res.data.total_scanned);
                $('#henkan_to_convert').text(totalTodo);
                $('#henkan_scan_results').slideDown();
                btn.text(henkan_ajax.i18n.starting).hide();
                
                if(totalTodo === 0) {
                    $('#henkan_status_text').text(henkan_ajax.i18n.done);
                }
            } else {
                alert(res.data.msg || henkan_ajax.i18n.error);
                btn.prop('disabled', false).text('Scan starten');
            }
        });
    });

    $('#henkan_start_convert').click(function() {
        $(this).hide();
        $('#henkan_progress_ui').show();
        processNextBatch();
    });

    function processNextBatch() {
        if(todoList.length === 0) {
            clearState();
            $('#henkan_status_text').text(henkan_ajax.i18n.done);
            $('.fill').css('width', '100%');
            $('#henkan_log_list').prepend('<li><strong>' + henkan_ajax.i18n.all_done + '</strong></li>');
            
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
                $('#henkan_log_list').prepend('<li style="color:red">Error: ' + res.data.msg + '</li>');
            }
            processNextBatch();
        }).fail(function() {
            $('#henkan_log_list').prepend('<li style="color:red">Server Error</li>');
            processNextBatch();
        });
    }
});

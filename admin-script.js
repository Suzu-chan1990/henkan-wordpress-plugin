jQuery(document).ready(function($) {
    const HENKAN_BULK_STATE = 'henkan_bulk_state_v1';
    function saveState(todo, total){ try{ localStorage.setItem(HENKAN_BULK_STATE, JSON.stringify({todo, total, ts: Date.now()})); }catch(e){} }
    function loadState(){ try{ const raw=localStorage.getItem(HENKAN_BULK_STATE); if(!raw) return null; const st=JSON.parse(raw); if(!st||!Array.isArray(st.todo)) return null; return st; }catch(e){ return null; } }
    function clearState(){ try{ localStorage.removeItem(HENKAN_BULK_STATE);}catch(e){} }
    // Render log lines as text only (never as HTML) to prevent XSS via file names
    function logLine(text, color, bold){
        const li = $('<li>').text(text);
        if(color) li.css('color', color);
        if(bold) li.css('font-weight', 'bold');
        $('#henkan_log_list').prepend(li);
    }
    const st0 = loadState();
    if(st0 && st0.todo.length>0){ $('#henkan_scan_results').show(); $('#henkan_to_convert').text(st0.todo.length); $('#henkan_resume_convert').show(); $('#henkan_status_text').text(henkan_ajax.i18n.ready_to_resume); }

    // --- NEW MAIN TABS LOGIC ---
    $('.main-tab-btn').on('click', function() {
        $('.main-tab-btn').removeClass('active').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        $('.main-tab-content').prop('hidden', true);
        $('#main-tab-' + $(this).data('target')).prop('hidden', false);
    });

    // --- INNER TABS LOGIC (APACHE / NGINX) ---
    $('.henkan-tab-btn').on('click', function() {
        const wrapper = $(this).closest('.henkan-inner-tabs-container');
        wrapper.find('.henkan-tab-btn').removeClass('active');
        $(this).addClass('active');
        wrapper.find('.henkan-tab-content').prop('hidden', true);
        wrapper.find('#tab-' + $(this).data('target')).prop('hidden', false);
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
        btn.prop('disabled', true).text('Scanning...');
        
        const bgQueue = !!henkan_ajax.enable_bg_queue;
        if (bgQueue) {
            $.post(henkan_ajax.ajax_url, {
                action: 'henkan_queue_start', nonce: henkan_ajax.nonce_scan,
                rescan_all: $('#henkan_bulk_rescan_all').is(':checked') ? 1 : 0,
                bulk_only_unconverted: $('#henkan_bulk_only_unconverted').is(':checked') ? 1 : 0,
                bulk_only_failed: $('#henkan_bulk_only_failed').is(':checked') ? 1 : 0
            }, function(res) {
                if(!res.success) { alert(res.data.msg || henkan_ajax.i18n.error); btn.prop('disabled', false); return; }
                $('#henkan_scan_results').slideDown(); btn.hide(); $('#henkan_to_convert').text('Background Queue');
                $('#henkan_status_text').text('Background queue running...'); pollQueue();
            }).fail(function(){ alert('Server Error'); btn.prop('disabled', false); });
            return;
        }

        // Scan phases: 'ids' (media library pages), then 'fs' (folder scan in time-boxed steps)
        let scanAfter = 0, scannedTotal = 0, scanPhase = 'ids', fsCursor = 0;
        todoList = [];
        function scanPage() {
            $.post(henkan_ajax.ajax_url, {
                action:'henkan_scan', nonce:henkan_ajax.nonce_scan, after_id:scanAfter, per_page:100, phase:scanPhase, fs_cursor:fsCursor,
                rescan_all:$('#henkan_bulk_rescan_all').is(':checked')?1:0,
                bulk_only_unconverted:$('#henkan_bulk_only_unconverted').is(':checked')?1:0,
                bulk_only_failed:$('#henkan_bulk_only_failed').is(':checked')?1:0
            }, function(res) {
                if(!res.success){ alert(res.data.msg || henkan_ajax.i18n.error); btn.prop('disabled',false); return; }
                todoList=todoList.concat(res.data.items||[]); scannedTotal += res.data.total_scanned||0;
                scanAfter=res.data.after_id||scanAfter; scanPhase=res.data.phase||'ids'; fsCursor=res.data.fs_cursor||0; $('#henkan_total_found').text(scannedTotal); $('#henkan_to_convert').text(todoList.length); $('#henkan_scan_results').show();
                if(res.data.has_more){ $('#henkan_status_text').text('Scanning...'); scanPage(); return; }
                totalTodo=todoList.length; saveState(todoList,totalTodo); btn.text(henkan_ajax.i18n.starting).hide();
                if(totalTodo===0) $('#henkan_status_text').text(henkan_ajax.i18n.done);
            }).fail(function(){ alert('Server Error'); btn.prop('disabled',false); });
        }
        scanPage();
    });

    function pollQueue() {
        $.post(henkan_ajax.ajax_url, {action:'henkan_queue_status',nonce:henkan_ajax.nonce_scan}, function(res){
            if(!res.success) return; const q=res.data.state||{};
            $('#henkan_status_text').text(q.active ? ('Background queue: '+(q.processed||0)+' processed') : 'Background queue finished.');
            if(q.active) setTimeout(pollQueue,3000); else $('.fill').css('width','100%');
        });
    }

    $('#henkan_start_convert').click(function() {
        // Restore the saved queue if the page was reloaded after a scan
        if(todoList.length === 0){ const st = loadState(); if(st){ todoList = st.todo; totalTodo = st.total || st.todo.length; } }
        $(this).hide();
        $('#henkan_progress_ui').show();
        processNextBatch();
    });

    $('#henkan_resume_convert').click(function() {
        const st = loadState();
        if(!st || st.todo.length === 0){ clearState(); return; }
        todoList = st.todo; totalTodo = st.total || st.todo.length;
        $(this).hide(); $('#henkan_start_convert').hide(); $('#henkan_start_scan').hide();
        $('#henkan_progress_ui').show();
        processNextBatch();
    });

    function processNextBatch() {
        if(todoList.length === 0) {
            clearState();
            $('#henkan_status_text').text(henkan_ajax.i18n.done);
            $('.fill').css('width', '100%');
            logLine(henkan_ajax.i18n.all_done, '', true);
            
            $.post(henkan_ajax.ajax_url, { action: 'henkan_clear_cache', nonce: henkan_ajax.nonce_scan }, function(res) {
                 if(res.success && res.data.msg) {
                     logLine(res.data.msg, '#46b450', true);
                 }
            });
            return;
        }

        let chunk = todoList.splice(0, 1);
        saveState(todoList, totalTodo); // persist progress so Resume works after a reload 
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
                logLine(res.data.msg);
            } else {
                logLine('Error: ' + ((res.data && res.data.msg) || ''), 'red');
            }
            processNextBatch();
        }).fail(function() {
            logLine('Server Error', 'red');
            processNextBatch();
        });
    }
});

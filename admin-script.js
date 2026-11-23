jQuery(document).ready(function($) {
    const HENKAN_BULK_STATE = 'henkan_bulk_state_v1';
    let bulkForce = 0, forceSince = 0;
    function saveState(todo, total){ try{ localStorage.setItem(HENKAN_BULK_STATE, JSON.stringify({todo, total, force: bulkForce, since: forceSince, ts: Date.now()})); }catch(e){} }
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

    // Media library: "Optimize"/"Retry" button in the Henkan column
    function refreshCell(id, html){
        const cell = $('#post-' + id + ' .column-henkan_status');
        if (cell.length && html) cell.html(html); // server-rendered, escaped markup
    }
    $(document).on('click', '.henkan-quick-convert', function(e) {
        e.preventDefault();
        const btn = $(this);
        const spinner = btn.nextAll('.henkan-spinner').first();
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
                refreshCell(id, res.data.column_html);
            } else {
                btn.show().text(henkan_ajax.i18n.retry);
                window.alert((res.data && res.data.msg) || henkan_ajax.i18n.server_error);
            }
        }).fail(function() {
            spinner.removeClass('is-active');
            btn.show();
            window.alert(henkan_ajax.i18n.server_error);
        });
    });

    // Media library row actions: re-convert, re-convert with quality, revert
    $(document).on('click', '.henkan-row-action', function(e) {
        e.preventDefault();
        const link = $(this);
        const id = link.data('id');
        const kind = link.data('henkan');
        const data = { nonce: henkan_ajax.nonce_convert, item: id };

        if (kind === 'revert') {
            if (!window.confirm(henkan_ajax.i18n.confirm_revert)) return;
            data.action = 'henkan_revert';
        } else {
            data.action = 'henkan_convert';
            data.force = 1;
            if (kind === 'quality') {
                const q = parseInt(window.prompt(henkan_ajax.i18n.ask_quality, '82'), 10);
                if (!q || q < 1 || q > 100) return;
                data.quality = q;
            }
        }

        const label = link.text();
        link.text('…');
        $('#post-' + id + ' .column-henkan_status .henkan-spinner').addClass('is-active');
        $.post(henkan_ajax.ajax_url, data, function(res) {
            link.text(label);
            if (res.success) {
                refreshCell(id, res.data.column_html);
            } else {
                $('#post-' + id + ' .column-henkan_status .henkan-spinner').removeClass('is-active');
                window.alert((res.data && res.data.msg) || henkan_ajax.i18n.server_error);
            }
        }).fail(function() {
            link.text(label);
            window.alert(henkan_ajax.i18n.server_error);
        });
    });

    // Settings page: "Revert all conversions" in batches
    $('#henkan_revert_all').on('click', function(e) {
        e.preventDefault();
        if (!window.confirm(henkan_ajax.i18n.confirm_revert_all)) return;
        const btn = $(this).prop('disabled', true);
        const status = $('#henkan_revert_status').text(henkan_ajax.i18n.reverting);
        let reverted = 0, skipped = 0;
        function step(after) {
            $.post(henkan_ajax.ajax_url, { action: 'henkan_revert_batch', nonce: henkan_ajax.nonce_convert, after_id: after }, function(res) {
                if (!res.success) { status.text((res.data && res.data.msg) || henkan_ajax.i18n.server_error); btn.prop('disabled', false); return; }
                reverted += res.data.reverted; skipped += res.data.skipped;
                status.text(henkan_ajax.i18n.reverting + ' ' + reverted);
                if (res.data.more) { step(res.data.last_id); return; }
                status.text(henkan_ajax.i18n.revert_done.replace('%1$s', reverted).replace('%2$s', skipped));
                btn.prop('disabled', false);
            }).fail(function() { status.text(henkan_ajax.i18n.server_error); btn.prop('disabled', false); });
        }
        step(0);
    });

    // Bulk Process Logic
    let todoList = [];
    let totalTodo = 0;

    $('#henkan_start_scan').click(function(e) {
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true).text(henkan_ajax.i18n.scanning);
        
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
                $('#henkan_status_text').text(henkan_ajax.i18n.queue_running); pollQueue();
            }).fail(function(){ alert(henkan_ajax.i18n.server_error); btn.prop('disabled', false); });
            return;
        }

        // Scan phases: 'ids' (media library pages), then 'fs' (folder scan in time-boxed steps)
        let scanAfter = 0, scannedTotal = 0, scanPhase = 'ids', fsCursor = 0;
        todoList = []; forceSince = 0;
        bulkForce = $('#henkan_bulk_rescan_all').is(':checked') ? 1 : 0;
        function scanPage() {
            $.post(henkan_ajax.ajax_url, {
                action:'henkan_scan', nonce:henkan_ajax.nonce_scan, after_id:scanAfter, per_page:100, phase:scanPhase, fs_cursor:fsCursor,
                rescan_all:$('#henkan_bulk_rescan_all').is(':checked')?1:0,
                bulk_only_unconverted:$('#henkan_bulk_only_unconverted').is(':checked')?1:0,
                bulk_only_failed:$('#henkan_bulk_only_failed').is(':checked')?1:0
            }, function(res) {
                if(!res.success){ alert(res.data.msg || henkan_ajax.i18n.error); btn.prop('disabled',false); return; }
                if(!forceSince && res.data.server_time) forceSince = res.data.server_time;
                todoList=todoList.concat(res.data.items||[]); scannedTotal += res.data.total_scanned||0;
                scanAfter=res.data.after_id||scanAfter; scanPhase=res.data.phase||'ids'; fsCursor=res.data.fs_cursor||0; $('#henkan_total_found').text(scannedTotal); $('#henkan_to_convert').text(todoList.length); $('#henkan_scan_results').show();
                if(res.data.has_more){ $('#henkan_status_text').text(henkan_ajax.i18n.scanning); scanPage(); return; }
                totalTodo=todoList.length; saveState(todoList,totalTodo); btn.text(henkan_ajax.i18n.starting).hide();
                if(totalTodo===0) $('#henkan_status_text').text(henkan_ajax.i18n.done);
            }).fail(function(){ alert(henkan_ajax.i18n.server_error); btn.prop('disabled',false); });
        }
        scanPage();
    });

    function pollQueue() {
        $.post(henkan_ajax.ajax_url, {action:'henkan_queue_status',nonce:henkan_ajax.nonce_scan}, function(res){
            if(!res.success) return; const q=res.data.state||{};
            $('#henkan_status_text').text(q.active ? (henkan_ajax.i18n.queue_running+' '+(q.processed||0)) : henkan_ajax.i18n.queue_done);
            if(q.active) setTimeout(pollQueue,3000); else $('.fill').css('width','100%');
        });
    }

    $('#henkan_start_convert').click(function() {
        // Restore the saved queue if the page was reloaded after a scan
        if(todoList.length === 0){ const st = loadState(); if(st){ todoList = st.todo; totalTodo = st.total || st.todo.length; bulkForce = st.force || 0; forceSince = st.since || 0; } }
        $(this).hide();
        $('#henkan_progress_ui').show();
        processNextBatch();
    });

    $('#henkan_resume_convert').click(function() {
        const st = loadState();
        if(!st || st.todo.length === 0){ clearState(); return; }
        todoList = st.todo; totalTodo = st.total || st.todo.length; bulkForce = st.force || 0; forceSince = st.since || 0;
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
            item: chunk[0],
            force: bulkForce,
            force_since: forceSince
        }, function(res) {
            if(res.success) {
                logLine(res.data.msg);
            } else {
                logLine('Error: ' + ((res.data && res.data.msg) || ''), 'red');
            }
            processNextBatch();
        }).fail(function() {
            logLine(henkan_ajax.i18n.server_error, 'red');
            processNextBatch();
        });
    }
});

<?php

// ============================================================================
// Registrazione pagina Strumenti nel menu Concessionari
// ============================================================================
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=concessionario',
        'Strumenti Concessionari',
        'Strumenti',
        'manage_options',
        'rc-strumenti',
        'rc_admin_strumenti_render'
    );
});

// ============================================================================
// Rendering pagina
// ============================================================================
function rc_admin_strumenti_render() {
    // Rileva snippet pericolosi ancora attivi
    $snippet_hooks = [
        'rc_recreation_classe_sconto'        => has_action('wp_loaded', 'rc_recreation_classe_sconto'),
        'delete_all_posts_of_custom_post_type' => has_action('wp_loaded', 'delete_all_posts_of_custom_post_type'),
        'delete_all_classe_sconto'           => has_action('wp_loaded', 'delete_all_classe_sconto'),
    ];
    $has_dangerous_hooks = array_filter($snippet_hooks);
    ?>
    <div class="wrap">
        <h1>🛠 Strumenti Concessionari</h1>

        <?php if ($has_dangerous_hooks): ?>
        <div class="notice notice-error">
            <p>
                <strong>⚠ Attenzione: snippet pericolosi rilevati!</strong><br>
                I seguenti hook su <code>wp_loaded</code> sono ancora attivi e vengono eseguiti ad ogni caricamento di pagina.
                Disabilitali immediatamente nel gestore degli snippet:
            </p>
            <ul style="list-style:disc;padding-left:20px">
                <?php foreach (array_keys($has_dangerous_hooks) as $fn): ?>
                    <li><code><?= esc_html($fn) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php rc_admin_notice_box() ?>

        <div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:20px;align-items:flex-start">

            <!-- CARD: Ricrea Province -->
            <div class="card" style="min-width:320px;max-width:480px;padding:20px">
                <h2 style="margin-top:0">🗺 Ricrea Province</h2>
                <p>
                    Elimina tutte le province e le regioni dalla tassonomia
                    <code>concessionario_provincia</code> e le ricrea
                    scaricando il CSV configurato nelle <a href="<?= admin_url('edit.php?post_type=concessionario&page=concessionari-opzioni') ?>">Opzioni</a>.
                </p>
                <button id="rc-btn-province" class="button button-primary">
                    Ricrea Province
                </button>
                <?php rc_admin_spinner('rc-spinner-province') ?>
            </div>

            <!-- CARD: Elimina Concessionari -->
            <div class="card" style="min-width:320px;max-width:480px;padding:20px;border-left:4px solid #d63638">
                <h2 style="margin-top:0;color:#d63638">⚠ Elimina tutti i Concessionari</h2>
                <p>
                    Elimina <strong>permanentemente</strong> tutti i post di tipo
                    <code>concessionario</code> incluse le relative tassonomie e campi ACF.
                    L'operazione è irreversibile.
                </p>
                <button id="rc-btn-delete" class="button" style="color:#d63638;border-color:#d63638">
                    Elimina tutti i concessionari
                </button>
                <?php rc_admin_progress_bar('rc') ?>
            </div>

        </div>
    </div>

    <?php rc_admin_enqueue_script('rc', 'concessionario', wp_create_nonce('rc_admin_action')) ?>
    <?php
}

// ============================================================================
// Helper HTML
// ============================================================================
function rc_admin_notice_box() {
    echo '<div id="rc-notice" style="margin-top:16px;display:none"></div>';
}

function rc_admin_spinner($id) {
    echo '<span id="' . esc_attr($id) . '" class="spinner" style="float:none;visibility:hidden;margin-left:8px"></span>';
}

function rc_admin_progress_bar($prefix) { ?>
    <div id="<?= $prefix ?>-progress-wrap" style="display:none;margin-top:16px">
        <div style="background:#f0f0f1;border-radius:3px;height:22px;overflow:hidden;border:1px solid #c3c4c7">
            <div id="<?= $prefix ?>-progress-bar"
                 style="background:#2271b1;height:100%;width:0%;transition:width .25s ease;display:flex;align-items:center;justify-content:center">
            </div>
        </div>
        <p id="<?= $prefix ?>-progress-text" style="margin:6px 0 0;color:#646970;font-size:13px"></p>
    </div>
<?php }

// ============================================================================
// Script AJAX inline condiviso tra le due pagine (parametrizzato)
// ============================================================================
function rc_admin_enqueue_script($prefix, $post_type, $nonce) { ?>
<script>
jQuery(function($) {
    const PREFIX    = '<?= esc_js($prefix) ?>';
    const POST_TYPE = '<?= esc_js($post_type) ?>';
    const NONCE     = '<?= esc_js($nonce) ?>';
    const BATCH     = 30;

    function showNotice(type, msg) {
        $('#' + PREFIX + '-notice')
            .attr('class', 'notice notice-' + type + ' is-dismissible')
            .html('<p>' + msg + '</p>')
            .show();
    }

    // ---- Ricrea Province ----
    $('#' + PREFIX + '-btn-province').on('click', function() {
        const btn     = $(this);
        const spinner = $('#' + PREFIX + '-spinner-province');
        btn.prop('disabled', true);
        spinner.css('visibility', 'visible');
        $('#' + PREFIX + '-notice').hide();

        $.post(ajaxurl, {
            action : PREFIX + '_ricrea_province',
            nonce  : NONCE
        })
        .done(function(res) {
            if (res.success) {
                showNotice('success', res.data.message);
            } else {
                showNotice('error', res.data.message || 'Errore sconosciuto.');
            }
        })
        .fail(function() { showNotice('error', 'Errore di rete.'); })
        .always(function() {
            btn.prop('disabled', false);
            spinner.css('visibility', 'hidden');
        });
    });

    // ---- Elimina post (con progress) ----
    $('#' + PREFIX + '-btn-delete').on('click', function() {
        if (!confirm('Sei sicuro di voler eliminare TUTTI i ' + POST_TYPE + '?\nQuesta operazione è irreversibile.')) return;

        const btn  = $(this);
        btn.prop('disabled', true);
        $('#' + PREFIX + '-notice').hide();
        $('#' + PREFIX + '-progress-wrap').show();
        $('#' + PREFIX + '-progress-bar').css('width', '0%');
        $('#' + PREFIX + '-progress-text').text('Conteggio in corso…');

        // Step 1 – ottieni il totale
        $.post(ajaxurl, {
            action : PREFIX + '_delete_posts',
            nonce  : NONCE,
            step   : 'init'
        })
        .done(function(res) {
            if (!res.success) { return finish(false, res.data.message); }
            const total = parseInt(res.data.total, 10);
            if (total === 0) { return finish(true, 'Nessun elemento trovato.'); }
            runBatch(total, 0);
        })
        .fail(function() { finish(false, 'Errore di rete durante il conteggio.'); });

        function runBatch(total, deleted) {
            $.post(ajaxurl, {
                action : PREFIX + '_delete_posts',
                nonce  : NONCE,
                step   : 'batch',
                batch  : BATCH
            })
            .done(function(res) {
                if (!res.success) { return finish(false, res.data.message); }
                deleted += parseInt(res.data.deleted, 10);
                const pct = Math.min(100, Math.round((deleted / total) * 100));
                $('#' + PREFIX + '-progress-bar').css('width', pct + '%').text(pct + '%');
                $('#' + PREFIX + '-progress-text').text('Eliminati ' + deleted + ' di ' + total + '…');

                if (res.data.done) {
                    finish(true, 'Completato: eliminati ' + deleted + ' elementi.');
                } else {
                    setTimeout(function() { runBatch(total, deleted); }, 100);
                }
            })
            .fail(function() { finish(false, 'Errore di rete durante l\'eliminazione.'); });
        }

        function finish(ok, msg) {
            showNotice(ok ? 'success' : 'error', msg);
            btn.prop('disabled', false);
            if (ok) {
                $('#' + PREFIX + '-progress-bar').css('width', '100%').text('100%');
                $('#' + PREFIX + '-progress-text').text(msg);
            }
        }
    });
});
</script>
<?php }

// ============================================================================
// AJAX: Ricrea Province
// ============================================================================
add_action('wp_ajax_rc_ricrea_province', function() {
    check_ajax_referer('rc_admin_action', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Non autorizzato.']);

    rc_recreation_classe_sconto();
    wp_send_json_success(['message' => 'Province ricreate con successo.']);
});

// ============================================================================
// AJAX: Elimina concessionari (in batch)
// ============================================================================
add_action('wp_ajax_rc_delete_posts', function() {
    check_ajax_referer('rc_admin_action', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Non autorizzato.']);

    $step = sanitize_text_field($_POST['step'] ?? '');

    if ($step === 'init') {
        $ids   = get_posts(['post_type' => 'concessionario', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
        wp_send_json_success(['total' => count($ids)]);
    }

    if ($step === 'batch') {
        $batch = max(1, (int)($_POST['batch'] ?? 30));
        $ids   = get_posts(['post_type' => 'concessionario', 'post_status' => 'any', 'posts_per_page' => $batch, 'fields' => 'ids']);

        foreach ($ids as $id) {
            wp_set_object_terms($id, [], 'concessionario_scuola');
            wp_set_object_terms($id, [], 'concessionario_provincia');
            wp_delete_post($id, true);
        }

        $remaining = get_posts(['post_type' => 'concessionario', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids']);
        wp_send_json_success(['deleted' => count($ids), 'done' => empty($remaining)]);
    }

    wp_send_json_error(['message' => 'Step non valido.']);
});

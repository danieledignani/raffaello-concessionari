<?php

function rc_register_concessionari_rest_api_add() {
    register_rest_route('wc/v3', 'concessionari/bulk', [
        'methods' => 'POST',
        'callback' => 'rc_sync_concessionari_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ]);

    register_rest_route('wc/v3', 'concessionari/(?P<id>\\d+)', [
        'methods' => 'POST',
        'callback' => 'rc_upsert_single_concessionario_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ]);

    register_rest_route('wc/v3', 'concessionari/(?P<id>\\d+)', [
        'methods' => 'DELETE',
        'callback' => 'rc_delete_single_concessionario_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ]);

    register_rest_route('wc/v3', 'concessionari', [
        'methods' => 'GET',
        'callback' => 'rc_get_concessionari_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ]);
}

function rc_concessionari_permission_check($request) {
    return current_user_can('manage_options');
}

function rc_sync_concessionari_callback($request) {
    $incoming = $request->get_json_params();
    $updated_ids = [];

    foreach ($incoming as $concessionario) {
        $post_id = rc_upsert_concessionario($concessionario);
        if ($post_id) {
            $updated_ids[] = $post_id;
        }
    }

    return new WP_REST_Response(['messaggio' => 'Concessionari sincronizzati', 'post_ids' => $updated_ids], 200);
}

function rc_upsert_single_concessionario_callback($request) {
    $id = $request->get_param('id');
    $concessionario = $request->get_json_params();
    $concessionario['portaleconcessionari_id'] = $id;

    $post_id = rc_upsert_concessionario($concessionario);

    if (!$post_id) {
        return new WP_REST_Response(['errore' => 'Errore in creazione/aggiornamento'], 500);
    }

    return new WP_REST_Response(['messaggio' => 'Concessionario aggiornato con successo', 'post_id' => $post_id], 200);
}

function rc_delete_single_concessionario_callback($request) {
    $id = $request->get_param('id');

    $existing = get_posts([
        'post_type' => 'concessionario',
        'posts_per_page' => 1,
        'post_status' => 'any',
        'meta_query' => [[
            'key' => 'portaleconcessionari_id',
            'value' => $id
        ]]
    ]);

    if (!$existing) {
        return new WP_REST_Response(['errore' => 'Concessionario non trovato'], 404);
    }

    $post_id = $existing[0]->ID;

    // Rimuove tassonomie
    wp_set_object_terms($post_id, [], 'concessionario_scuola');
    wp_set_object_terms($post_id, [], 'concessionario_provincia');

    // Rimuove campi ACF noti
    $acf_fields = [
        'classi_sconto', 'telefoni', 'cellulari', 'nome', 'email',
        'pec', 'partita_iva', 'portaleconcessionari_id', 'avoid_classi_sconto_sync'
    ];
    foreach ($acf_fields as $field) {
        delete_field($field, $post_id);
    }

    $result = wp_delete_post($post_id, true);

    if (!$result) {
        return new WP_REST_Response(['errore' => 'Errore durante l\'eliminazione'], 500);
    }

    return new WP_REST_Response(['messaggio' => "Concessionario eliminato", 'post_id' => $post_id], 200);
}

function rc_upsert_concessionario(array $concessionario): ?int {
    $province_obj = rc_get_province_obj();

    if (empty($concessionario['portaleconcessionari_id'])) return null;

    $existing = get_posts([
        'post_type' => 'concessionario',
        'posts_per_page' => 1,
        'post_status' => 'any',
        'meta_query' => [[
            'key'   => 'portaleconcessionari_id',
            'value' => $concessionario['portaleconcessionari_id']
        ]]
    ]);

    $post_id = $existing ? $existing[0]->ID : null;
    $conc_data = [
        'post_title'  => $concessionario['titolo'],
        'post_type'   => 'concessionario',
        'post_status' => 'publish',
    ];

    $post_id = $post_id ? wp_update_post(['ID' => $post_id] + $conc_data) : wp_insert_post($conc_data);
    if (!$post_id) return null;

    $fields = ['nome', 'email', 'pec', 'partita_iva', 'portaleconcessionari_id'];
    foreach ($fields as $field) {
        update_field($field, $concessionario[$field] ?? '', $post_id);
    }

    update_field('telefoni', array_map(fn($v) => ['telefono' => $v], $concessionario['telefoni'] ?? []), $post_id);
    update_field('cellulari', array_map(fn($v) => ['cellulare' => $v], $concessionario['cellulari'] ?? []), $post_id);

    if (!get_field('avoid_classi_sconto_sync', $post_id) && !empty($concessionario['classi_sconto'])) {
        rc_update_classi_sconto($post_id, $concessionario['classi_sconto'], $province_obj);
    }

    return $post_id;
}

function rc_update_classi_sconto($post_id, $classi_sconto, $province_obj) {
    $grouped = [];
    $scuole_slugs = [];
    $province_slugs = [];

    foreach ($classi_sconto as $cs) {
        $scuola_slug = $cs['scuola'];
        $scuole_slugs[] = $scuola_slug;
        $scuola_term = get_term_by('slug', $scuola_slug, 'concessionario_scuola');
        if (!$scuola_term) continue;

        $scuola_id = (int)$scuola_term->term_id;
        if (!isset($grouped[$scuola_id])) {
            $grouped[$scuola_id] = [
                'scuola' => $scuola_id,
                'email' => $cs['email'] ?? '',
                'zone'  => [],
            ];
        }

        foreach ($cs['zone'] as $zona) {
            $provincia_slug = rc_get_provincia_slug_from_sigla($province_obj, $zona['provincia']);
            $province_slugs[] = $provincia_slug;
            $prov_term = get_term_by('slug', $provincia_slug, 'concessionario_provincia');
            if (!$prov_term) continue;

            $tipo = [];
            if (!empty($zona['vendita'])) $tipo[] = 'vendita';
            if (!empty($zona['propaganda'])) $tipo[] = 'promozione';

            $grouped[$scuola_id]['zone'][] = [
                'provincia' => (int)$prov_term->term_id,
                'tipo' => $tipo
            ];
        }
    }

    update_field('classi_sconto', array_values($grouped), $post_id);
    rc_insert_taxonomies_with_slugs($post_id, $scuole_slugs, 'concessionario_scuola');
    rc_insert_taxonomies_with_slugs($post_id, $province_slugs, 'concessionario_provincia');
}


function rc_get_concessionari_callback($request) {
    $province_obj = rc_get_province_obj();

    $query = new WP_Query([
        'post_type' => 'concessionario',
        'post_status' => 'publish',
        'posts_per_page' => -1
    ]);

    $output = [];
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $acf = get_fields($post_id);

        $telefoni = [];
        if (!empty($acf['telefoni']) && is_array($acf['telefoni'])) {
            foreach ($acf['telefoni'] as $r) {
                if (!empty($r['telefono'])) {
                    $telefoni[] = $r['telefono'];
                }
            }
        }

        $cellulari = [];
        if (!empty($acf['cellulari']) && is_array($acf['cellulari'])) {
            foreach ($acf['cellulari'] as $r) {
                if (!empty($r['cellulare'])) {
                    $cellulari[] = $r['cellulare'];
                }
            }
        }

        $classi_sconto = [];
        if (!empty($acf['classi_sconto']) && is_array($acf['classi_sconto'])) {
            foreach ($acf['classi_sconto'] as $cs) {
                $scuola_term = get_term($cs['scuola'], 'concessionario_scuola');
                $scuola_slug = $scuola_term ? $scuola_term->slug : '';

                $zone = [];
                foreach ($cs['zone'] ?? [] as $zona) {
                    $prov_term = get_term($zona['provincia'], 'concessionario_provincia');
                    $provincia_name = $prov_term ? $prov_term->name : '';
                    $sigla = rc_get_provincia_sigla_from_nome($province_obj, $provincia_name);

                    $zone[] = [
                        'provincia' => $sigla ?? '',
                        'vendita' => in_array('vendita', $zona['tipo'] ?? []),
                        'propaganda' => in_array('promozione', $zona['tipo'] ?? []),
                    ];
                }

                $classi_sconto[] = [
                    'scuola' => $scuola_slug,
                    'email' => $cs['email'] ?? '',
                    'zone' => $zone
                ];
            }
        }

        $output[] = [
            'titolo' => get_the_title(),
            'nome' => $acf['nome'] ?? '',
            'email' => $acf['email'] ?? '',
            'pec' => $acf['pec'] ?? '',
            'partita_iva' => $acf['partita_iva'] ?? '',
            'portaleconcessionari_id' => $acf['portaleconcessionari_id'] ?? '',
            'telefoni' => $telefoni,
            'cellulari' => $cellulari,
            'classi_sconto' => $classi_sconto,
        ];
    }
    wp_reset_postdata();

    return new WP_REST_Response($output, 200);
}


function rc_insert_taxonomies_with_slugs($post_id, $slugs, $taxonomy) {
    $slugs = array_unique($slugs);
    $valid_slugs = [];

    foreach ($slugs as $slug) {
        $term = get_term_by('slug', strtolower($slug), $taxonomy);
        if ($term) $valid_slugs[] = $term->slug;
    }

    if (!empty($valid_slugs)) {
        $result = wp_set_object_terms($post_id, $valid_slugs, $taxonomy, false);
        if (is_wp_error($result)) {
            wc_get_logger()->error("Errore impostando la tassonomia $taxonomy per post $post_id: " . wc_print_r($result, true));
        }
    }
}

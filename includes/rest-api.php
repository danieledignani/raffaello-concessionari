<?php
// Crea REST API per la gestione dei concessionari
function rc_register_concessionari_rest_api_add()
{
    register_rest_route('wc/v3', 'concessionari_update', array(
        'methods' => 'POST',
        'callback' => 'rc_create_and_update_concessionari_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ));

    register_rest_route('wc/v3', 'concessionario_update', array(
        'methods' => 'POST',
        'callback' => 'rc_update_concessionario_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ));

    register_rest_route('wc/v3', 'concessionari', array(
        'methods' => 'GET',
        'callback' => 'rc_get_concessionari_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ));
}

function rc_concessionari_permission_check($request)
{
    // WooCommerce gestisce automaticamente la verifica OAuth.
    return current_user_can('manage_options');
}

function rc_wprc_startsWith($string, $startString)
{
    return strncmp($string, $startString, strlen($startString)) === 0;
}

function rc_create_and_update_concessionari_callback($request)
{
    wc_get_logger()->debug('Start update concessionari'.wc_print_r($request, true));
    $province_obj = rc_get_province_obj();

    $incoming = $request->get_json_params();
    $incoming_ids = array_column($incoming, 'portaleconcessionari_id');

    // Trova i post esistenti con portaleconcessionari_id
    $existing_posts = get_posts([
        'post_type' => 'concessionario',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'meta_query' => [
            [
                'key'     => 'portaleconcessionari_id',
                'compare' => 'EXISTS',
            ]
        ]
    ]);

    $existing_map = [];
    foreach ($existing_posts as $post) {
        $id = get_field('portaleconcessionari_id', $post->ID);
        if ($id) $existing_map[$id] = $post->ID;
    }

    $updated_ids = [];

    foreach ($incoming as $concessionario) {
        $concessionario_id = $concessionario['portaleconcessionari_id'];
        $post_id = $existing_map[$concessionario_id] ?? null;

        $conc_data = array(
            'post_title'   => $concessionario['titolo'],
            'post_type'    => 'concessionario',
            'post_status'  => 'publish',
        );

        if ($post_id) {
            $conc_data['ID'] = $post_id;
            $post_id = wp_update_post($conc_data);
        } else {
            $post_id = wp_insert_post($conc_data);
        }

        if (!$post_id) continue;
        $updated_ids[] = $post_id;

        // Aggiorna i campi


        $fields = ['nome', 'email', 'pec', 'partita_iva', 'portaleconcessionari_id'];
        foreach ($fields as $field_key) {
            update_field($field_key, $concessionario[$field_key] ?? '', $post_id);
        }

        // Telefoni (repeater)
        $telefoni = [];
        foreach (($concessionario['telefoni'] ?? []) as $tel) {
            $telefoni[] = ['telefono' => $tel];
        }
        update_field('telefoni', $telefoni, $post_id);

        // Cellulari (repeater)
        $cellulari = [];
        foreach (($concessionario['cellulari'] ?? []) as $cel) {
            $cellulari[] = ['cellulare' => $cel];
        }
        update_field('cellulari', $cellulari, $post_id);

        $avoid_classi_sconto_sync = get_field('avoid_classi_sconto_sync', $post_id);

        wc_get_logger()->debug("Post ID $post_id no_sync $avoid_classi_sconto_sync");

        if ($avoid_classi_sconto_sync) {
            continue;
        }
        // Classi sconto
        $classi_sconto_grouped = [];
        $concessionari_scuole_slugs = [];
        $concessionari_province_slugs = [];

        foreach ($concessionario['classi_sconto'] as $classe_sconto) {
            $scuola_slug = $classe_sconto['scuola'];
            $email = $classe_sconto['email'];
            $concessionari_scuole_slugs[] = $scuola_slug;

            $scuola_term = get_term_by('slug', $scuola_slug, 'concessionario_scuola');
            if (!$scuola_term) continue;
            $scuola_id = (int)$scuola_term->term_id;

            if (!isset($classi_sconto_grouped[$scuola_id])) {
                $classi_sconto_grouped[$scuola_id] = [
                    'scuola' => $scuola_id,
                    'email' => $email,
                    'zone' => [],
                ];
            }

            foreach ($classe_sconto['zone'] as $zona) {
                $provincia_slug = rc_get_provincia_slug_from_sigla($province_obj, $zona['provincia']);
                $concessionari_province_slugs[] = $provincia_slug;
                $provincia_term = get_term_by('slug', $provincia_slug, 'concessionario_provincia');
                if (!$provincia_term) continue;

                $tipo = [];
                if (!empty($zona['vendita'])) $tipo[] = 'vendita';
                if (!empty($zona['propaganda'])) $tipo[] = 'promozione';

                $classi_sconto_grouped[$scuola_id]['zone'][] = [
                    'provincia' => (int)$provincia_term->term_id,
                    'tipo' => $tipo,
                ];
            }
        }

        $acf_classi_sconto = array_values($classi_sconto_grouped);
        update_field('classi_sconto', $acf_classi_sconto, $post_id);

        rc_insert_taxonomies_with_slugs($post_id, $concessionari_scuole_slugs, 'concessionario_scuola');
        rc_insert_taxonomies_with_slugs($post_id, $concessionari_province_slugs, 'concessionario_provincia');
    }

    function rc_update_concessionario_callback($request)
    {
        wc_get_logger()->debug('Start single update'.wc_print_r($request, true));

        $province_obj = rc_get_province_obj();
        $concessionario = $request->get_json_params();

        if (empty($concessionario['portaleconcessionari_id'])) {
            return new WP_REST_Response(['errore' => 'portaleconcessionari_id mancante'], 400);
        }

        $post_id = null;
        $existing = get_posts([
            'post_type' => 'concessionario',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key'   => 'portaleconcessionari_id',
                    'value' => $concessionario['portaleconcessionari_id']
                ]
            ]
        ]);

        if ($existing) {
            $post_id = $existing[0]->ID;
        }

        $conc_data = [
            'post_title'   => $concessionario['titolo'],
            'post_type'    => 'concessionario',
            'post_status'  => 'publish',
        ];

        if ($post_id) {
            $conc_data['ID'] = $post_id;
            $post_id = wp_update_post($conc_data);
        } else {
            $post_id = wp_insert_post($conc_data);
        }

        if (!$post_id) {
            return new WP_REST_Response(['errore' => 'Errore nella creazione/aggiornamento del post'], 500);
        }

        // Campi base
        $fields = ['nome', 'email', 'pec', 'partita_iva', 'portaleconcessionari_id'];
        foreach ($fields as $field_key) {
            update_field($field_key, $concessionario[$field_key] ?? '', $post_id);
        }

        // Telefoni
        $telefoni = array_map(fn($tel) => ['telefono' => $tel], $concessionario['telefoni'] ?? []);
        update_field('telefoni', $telefoni, $post_id);

        $cellulari = array_map(fn($cel) => ['cellulare' => $cel], $concessionario['cellulari'] ?? []);
        update_field('cellulari', $cellulari, $post_id);

        // Flag no_sync
        $avoid_classi_sconto_sync = get_field('avoid_classi_sconto_sync', $post_id);
        wc_get_logger()->debug("Post ID $post_id avoid_classi_sconto_sync $avoid_classi_sconto_sync");

        if (!$avoid_classi_sconto_sync && !empty($concessionario['classi_sconto'])) {
            $classi_sconto_grouped = [];
            $concessionari_scuole_slugs = [];
            $concessionari_province_slugs = [];

            foreach ($concessionario['classi_sconto'] as $classe_sconto) {
                $scuola_slug = $classe_sconto['scuola'];
                $email = $classe_sconto['email'];
                $concessionari_scuole_slugs[] = $scuola_slug;

                $scuola_term = get_term_by('slug', $scuola_slug, 'concessionario_scuola');
                if (!$scuola_term) continue;
                $scuola_id = (int)$scuola_term->term_id;

                if (!isset($classi_sconto_grouped[$scuola_id])) {
                    $classi_sconto_grouped[$scuola_id] = [
                        'scuola' => $scuola_id,
                        'email' => $email,
                        'zone' => [],
                    ];
                }

                foreach ($classe_sconto['zone'] as $zona) {
                    $provincia_slug = rc_get_provincia_slug_from_sigla($province_obj, $zona['provincia']);
                    $concessionari_province_slugs[] = $provincia_slug;
                    $provincia_term = get_term_by('slug', $provincia_slug, 'concessionario_provincia');
                    if (!$provincia_term) continue;

                    $tipo = [];
                    if (!empty($zona['vendita'])) $tipo[] = 'vendita';
                    if (!empty($zona['propaganda'])) $tipo[] = 'promozione';

                    $classi_sconto_grouped[$scuola_id]['zone'][] = [
                        'provincia' => (int)$provincia_term->term_id,
                        'tipo' => $tipo,
                    ];
                }
            }

            $acf_classi_sconto = array_values($classi_sconto_grouped);
            update_field('classi_sconto', $acf_classi_sconto, $post_id);

            rc_insert_taxonomies_with_slugs($post_id, $concessionari_scuole_slugs, 'concessionario_scuola');
            rc_insert_taxonomies_with_slugs($post_id, $concessionari_province_slugs, 'concessionario_provincia');
        }

        return new WP_REST_Response(['messaggio' => 'Concessionario aggiornato con successo', 'post_id' => $post_id], 200);
    }


    // Elimina i post non più presenti nella lista
    // foreach ($existing_map as $id => $post_id) {
    //     if (!in_array($id, $incoming_ids)) {
    //         wp_delete_post($post_id, true);
    //         wc_get_logger()->info("Post ID $post_id deleted (not in incoming)");
    //     }
    // }

    return new WP_REST_Response(['messaggio' => 'Concessionari sincronizzati con successo!'], 200);
}


function rc_get_concessionari_callback($request)
{
    $args = array(
        'post_type'      => 'concessionario',
        'post_status'    => 'publish',
        'posts_per_page' => -1
    );

    $query = new WP_Query($args);
    $output = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            $acf_fields = get_fields($post_id);
            $scuole = wp_get_post_terms($post_id, 'concessionario_scuola', ['fields' => 'all']);
            $province = wp_get_post_terms($post_id, 'concessionario_provincia', ['fields' => 'all']);

            $output[] = array(
                'ID' => $post_id,
                'post_title' => get_the_title(),
                'acf_fields' => $acf_fields,
                'scuole' => $scuole,
                'province' => $province
            );
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response($output, 200);
}

function rc_insert_taxonomies_with_slugs($post_id, $slugs, $taxonomy)
{
    $slugs = array_unique($slugs);
    $all_slugs = $slugs; // Creiamo un nuovo array che conterrà anche gli slug dei padri

    if (count($slugs) > 0) {
        foreach ($slugs as $slug) {
            $slug = strtolower($slug);
            $term = get_term_by('slug', $slug, $taxonomy);
            if (!$term) {
                // Se non esiste lo slug, loggo un errore e lo tolgo dall'array
                wc_get_logger()->error("Term with slug {$slug} not found on concessionario id " . $post_id);
                $key = array_search($slug, $slugs);
                unset($slugs[$key]);
            }
        }

        $result = wp_set_object_terms($post_id, $all_slugs, $taxonomy, false);
        if (is_wp_error($result)) {
            wc_get_logger()->error("Failed to set object terms for post {$post_id} on concessionario" . wc_print_r($result, true));
        }
    }
}


function rc_delete_all_concessionari()
{
    $post_type = 'concessionario';

    // Ottieni tutti i post di un determinato tipo
    $args = array(
        'post_type'      => $post_type,
        'posts_per_page' => -1, // Seleziona tutti i post
        'post_status'    => 'any' // Includi post in qualsiasi stato
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Elimina il post
            wp_delete_post($post_id, true); // Imposta il secondo parametro su true per bypassare il cestino

            // Aggiungi un log per ogni post eliminato
            wc_get_logger()->info("Post ID $post_id deleted");
        }
        wp_reset_postdata();
    } else {
        wc_get_logger()->info("No posts found for post type: $post_type");
    }
}

function rc_admin_permission_api($request)
{
    return current_user_can('manage_options'); // Solo gli amministratori possono usare questo endpoint.
}

<?php
// Crea REST API per la gestione dei concessionari
function rc_register_concessionari_rest_api_add() {
    register_rest_route('wc/v3', 'concessionari_update', array(
        'methods' => 'POST',
        'callback' => 'rc_create_and_update_concessionari_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ));

    register_rest_route('wc/v3', 'concessionari', array(
        'methods' => 'GET',
        'callback' => 'rc_get_concessionari_callback',
        'permission_callback' => 'rc_concessionari_permission_check'
    ));

}

function rc_concessionari_permission_check($request) {
    // WooCommerce gestisce automaticamente la verifica OAuth.
    return current_user_can('manage_options');
}

function rc_wprc_startsWith($string, $startString) {
    return strncmp($string, $startString, strlen($startString)) === 0;
}
function rc_create_and_update_concessionari_callback( $request ) {
    $logger_array = array( 'source' => 'concessionari_rest_api_create_and_update' );
    wc_get_logger()->info( 'Start', $logger_array );

    rc_concessionari_log('Start update concessionari', $request);

    rc_delete_all_concessionari();

    rc_concessionari_log('Deleted all old concessionari');

    $province_obj = rc_get_province_obj();
    
    $concessionari = $request->get_json_params();

    foreach ($concessionari as $concessionario) {
        $conc_data = array(
            'post_title' => $concessionario['titolo'],
            'post_type' => 'concessionario',
            'post_status' => 'publish'
        );
        $post_id = wp_insert_post($conc_data);
        if ($post_id) {
            $fields = ['nome', 'email', 'email_infanzia', 'email_primaria', 'email_secondaria', 'pec', 'telefono', 'cellulare', 'partita_iva', 'portaleconcessionari_id'];
            foreach ($fields as $field_key) {
                if (!update_field($field_key, $concessionario[$field_key], $post_id)) {
                    wc_get_logger()->error("Failed to update field {$field_key} for post {$post_id} on concessionario".wc_print_r($concessionario, true), $logger_array);
                }
            }
        }

        // Raggruppiamo per scuola per nuova struttura repeater annidato
        $classi_sconto_grouped = [];

        // DEPRECATO: struttura legacy
        $concessionari_classi_sconto_infanzia = [];
        $concessionari_classi_sconto_primaria = [];
        $concessionari_classi_sconto_secondaria = [];
        // FINE DEPRECATO

        $concessionari_scuole_slugs = [];
        $concessionari_province_slugs = [];

        foreach ($concessionario['classi_sconto'] as $classe_sconto) {
            $provincia_slug = rc_get_provincia_slug_from_sigla($province_obj, $classe_sconto['provincia']);
            $scuola_slug = strtolower($classe_sconto['classe_sconto']);

            $concessionari_province_slugs[] = $provincia_slug;
            $concessionari_scuole_slugs[] = $scuola_slug;

            $provincia_term = get_term_by('slug', $provincia_slug, 'concessionario_provincia');
            $scuola_term = get_term_by('slug', $scuola_slug, 'concessionario_scuola');

            if (!$provincia_term || !$scuola_term) {
                wc_get_logger()->error("Termini mancanti per post_id {$post_id}", $logger_array );
                continue;
            }

            $provincia_id = (int) $provincia_term->term_id;
            $scuola_id = (int) $scuola_term->term_id;

            $tipo = [];
            if (!empty($classe_sconto['vendita'])) $tipo[] = 'vendita';
            if (!empty($classe_sconto['propaganda'])) $tipo[] = 'promozione';

            // Gruppo per scuola ID
            if (!isset($classi_sconto_grouped[$scuola_id])) {
                $classi_sconto_grouped[$scuola_id] = [];
            }

            $classi_sconto_grouped[$scuola_id][] = [
                'provincia' => $provincia_id,
                'tipo' => $tipo
            ];

            // DEPRECATO: supporto legacy per campi separati
            $entry = array(
                'provincia'   => $provincia_id,
                'vendita'     => in_array('vendita', $tipo),
                'promozione'  => in_array('promozione', $tipo),
            );
            switch ($scuola_slug) {
                case 'infanzia':
                    $concessionari_classi_sconto_infanzia[] = $entry;
                    break;
                case 'primaria':
                    $concessionari_classi_sconto_primaria[] = $entry;
                    break;
                case 'secondaria':
                    $concessionari_classi_sconto_secondaria[] = $entry;
                    break;
            }
            // FINE DEPRECATO
        }

        // ✅ Nuova struttura finale
        $acf_classi_sconto = [];
        foreach ($classi_sconto_grouped as $scuola_id => $zone) {
            $acf_classi_sconto[] = [
                'scuola' => $scuola_id,
                'zone'   => $zone
            ];
        }
        update_field('classi_sconto', $acf_classi_sconto, $post_id);

        // DEPRECATO: vecchi repeater singoli
        // update_field('classi_sconto_infanzia', $concessionari_classi_sconto_infanzia, $post_id);
        // update_field('classi_sconto_primaria', $concessionari_classi_sconto_primaria, $post_id);
        // update_field('classi_sconto_secondaria', $concessionari_classi_sconto_secondaria, $post_id);

        rc_insert_taxonomies_with_slugs($post_id, $concessionari_scuole_slugs, 'concessionario_scuola');
        rc_insert_taxonomies_with_slugs($post_id, $concessionari_province_slugs, 'concessionario_provincia');
    }

    return new WP_REST_Response(
        array('messaggio' => 'Concessionari aggiornati con successo!'), 200
    );
}

function rc_get_concessionari_callback($request) {
    $logger_array = array('source' => 'concessionari_rest_api_get');
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

function rc_insert_taxonomies_with_slugs($post_id, $slugs, $taxonomy) {
    $logger_array = array( 'source' => 'concessionari_rest_api_create_and_update' );
    $slugs = array_unique($slugs);
    $all_slugs = $slugs; // Creiamo un nuovo array che conterrà anche gli slug dei padri

    if(count($slugs) > 0){
        foreach ($slugs as $slug) {
            $slug = strtolower($slug);
            $term = get_term_by('slug', $slug, $taxonomy);
            if (!$term) {
                // Se non esiste lo slug, loggo un errore e lo tolgo dall'array
                wc_get_logger()->error("Term with slug {$slug} not found on concessionario id ".$post_id, $logger_array);
                $key = array_search($slug, $slugs);
                unset($slugs[$key]);
            }
        }

        $result = wp_set_object_terms($post_id, $all_slugs, $taxonomy, false);
        if (is_wp_error($result)) {
            wc_get_logger()->error("Failed to set object terms for post {$post_id} on concessionario".wc_print_r( $result, true ), $logger_array);
        }
    }
}


function rc_delete_all_concessionari() {
    $post_type = 'concessionario';
    $logger_array = array('source' => 'delete_all_posts');

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
            wc_get_logger()->info("Post ID $post_id deleted", $logger_array);
        }
        wp_reset_postdata();
    } else {
        wc_get_logger()->info("No posts found for post type: $post_type", $logger_array);
    }
}

function rc_admin_permission_api( $request ) {
    return current_user_can( 'manage_options' ); // Solo gli amministratori possono usare questo endpoint.
}

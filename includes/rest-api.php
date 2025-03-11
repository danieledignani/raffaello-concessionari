<?php
// Crea REST API per la gestione dei concessionari
function rc_register_concessionari_rest_api_add() {
    register_rest_route('wc/v3', 'concessionari_update', array(
        'methods' => 'POST',
        'callback' => 'rc_create_and_update_concessionari_callback',
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
    wc_get_logger()->info( 'Start', $logger_array  );

	rc_concessionari_log('Start update concessionari', $request);

    rc_delete_all_concessionari();

	rc_concessionari_log('Deleted all old concessionari');
    
    // Recupera i concessionari dal body della richiesta
    $concessionari = $request->get_json_params();
    // Aggiorna i concessionari nel database considerando che sono dei post di tipo 'concessionario'
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
                    // Log error or handle the case where the field could not be updated
                    wc_get_logger()->error( "Failed to update field {$field_key} for post {$post_id} on concessionario".wc_print_r( $concessionario, true ), $logger_array  );
                }
            }
        }
        $classi_sconto = $concessionario['classi_sconto'];
        $concessionari_scuole_slugs = [];
        $concessionari_province_slugs = [];
        $concessionari_classi_sconto_slugs = [];
        foreach ($classi_sconto as $classe_sconto) {
            $concessionari_scuole_slugs[] = $classe_sconto['classe_sconto'];

            $concessionari_province_slugs[] = $classe_sconto['provincia'];
            if ($classe_sconto['propaganda']) {
                $concessionari_classi_sconto_slugs[] = $classe_sconto['slug'].'-promozione';
            }
            if ($classe_sconto['vendita']) {
                $concessionari_classi_sconto_slugs[] = $classe_sconto['slug'].'-vendita';
            }
        }
        rc_insert_taxonomies_with_slugs($post_id, $concessionari_scuole_slugs, 'concessionario_scuola');
        rc_insert_taxonomies_with_slugs($post_id, $concessionari_province_slugs, 'concessionario_provincia');
        rc_insert_taxonomies_with_slugs($post_id, $concessionari_classi_sconto_slugs, 'concessionario_classe_sconto');

    }
    return new WP_REST_Response(
        array( 'messaggio' => 'Concessionari aggiornati con successo!'), 200 );
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
            } else {
                // Controlla se il termine ha un padre e aggiungi lo slug del padre
                if ($term->parent != 0) {
                    $parent_term = get_term_by('id', $term->parent, $taxonomy);
                    if ($parent_term && !in_array($parent_term->slug, $all_slugs)) {
                        $all_slugs[] = $parent_term->slug; // Aggiungi lo slug del padre se non è già nell'array
                    }
                }
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

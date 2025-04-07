<?php

function rc_create_province() {
    $province_obj = rc_get_province_obj();

    foreach ($province_obj as $provincia_obj) {
        $provincia_name = $provincia_obj['nome'];
        $regione_name = $provincia_obj['regione'];
        $regione_slug = rc_get_slug_value($regione_name, true);
        $provincia_slug = rc_get_slug_value($provincia_name); // Utilizzo della sigla per lo slug
        
        $existing_regione_id = term_exists($regione_slug, 'concessionario_provincia');

        //vecchio codice da sistemare
        if ($existing_regione_id) {
            // Se il termine esiste, prendi l'ID
            $regione_id = is_array($existing_regione_id) ? $existing_regione_id['term_id'] : $existing_regione_id;
        } else {
            // Se la regione non esiste, creala
            $term = wp_insert_term($regione_name, 'concessionario_provincia', ['slug' => $regione_slug]);

            if (is_wp_error($term)) {
                wc_get_logger()->error("Errore nella creazione della regione $regione_name: " . $term->get_error_message(), $logger_array);
            } else {
                $regione_id = $term['term_id']; // Memorizza l'ID del termine appena creato
            }
        }

        $existing_provincia_id = term_exists($provincia_slug, 'concessionario_provincia');
        if (!$existing_provincia_id) {
            $term = wp_insert_term($provincia_name, 'concessionario_provincia',
            [
                'slug' => $provincia_slug,
                'parent' => $regione_id
            ]
        );

            if (is_wp_error($term)) {
                wc_get_logger()->error("Errore nella creazione della provincia $provincia_name: " . $term->get_error_message(), $logger_array);
            }
        }
    }
}

function rc_get_province_obj(){
    $logger_array = array('source' => 'concessionari_classi_sconto_creation');
    wc_get_logger()->info('Start creating provinces', $logger_array);

    // URL del CSV con l'elenco delle province italiane corretto
    $csv_url = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTIATF8d26_XAAPaMQn2_tjhyv4p_QEbzHAJ1UK319yYiyzqfR1RxuWuRx_OVjtqDBKNYIEUgGI_R3_/pub?output=csv';
    $response = wp_remote_get($csv_url);

    if (is_wp_error($response)) {
        wc_get_logger()->error('Errore nel recupero delle province: ' . $response->get_error_message(), $logger_array);
        return;
    }
    $body = wp_remote_retrieve_body($response);
    $lines = explode("\n", $body);
    array_shift($lines); // togli intestazione csv
    $province_obj = [];

    foreach ($lines as $line) {
        $data = str_getcsv($line);
        if (!empty($data) && count($data) >= 3) {
            $province_obj[] = [
                'nome' => $data[0], // Provincia
                'sigla' => $data[1], // Sigla
                'regione' => $data[2] // Regione
            ];
        }
    }
    return $province_obj;
}

function rc_get_provincia_slug_from_sigla($province_obj, $sigla) {
    foreach ($province_obj as $provincia_obj) {
        if (strtolower($provincia_obj['sigla']) === strtolower($sigla)) {
            return $provincia_obj['nome'];
        }
    }
    return null; // Se non trovata, restituisce null
}

function rc_get_slug_value($string, $without_dash = false) {
    $string = strtolower($string); // Converti la stringa in minuscolo
    //replace ' with empty string
    $string = str_replace("'", '', $string);
    if($without_dash) {
        $string = trim(preg_replace('/[^a-z0-9]+/i', '', $string));
    } else {
        $string = trim(preg_replace('/[^a-z0-9]+/i', '-', $string), '-'); 
    }
    return $string;
}

function rc_delete_all_terms($taxonomy) {
    $logger_array = array('source' => 'concessionari_terms_deletion');
    wc_get_logger()->info('Start deleting all classe sconto terms', $logger_array);

    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    foreach ($terms as $term) {
        $result = wp_delete_term($term->term_id, $taxonomy);
        if (is_wp_error($result)) {
            wc_get_logger()->error('Errore nella cancellazione del termine: ' . $result->get_error_message(), $logger_array);
        } else {
            wc_get_logger()->info('Termine cancellato: ' . $term->term_id, $logger_array);
        }
    }
}

function rc_recreation_classe_sconto() {
    rc_delete_all_terms('concessionario_classe_sconto');
    rc_delete_all_terms('concessionario_provincia');
    rc_create_province();
}

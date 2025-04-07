<?php

add_action('init', function() {
    // Solo scuola
    add_rewrite_rule(
        '^concessionari/scuola-([^/]+)/?$',
        'index.php?post_type=concessionario&concessionario_scuola=$matches[1]',
        'top'
    );

    // Solo provincia
    add_rewrite_rule(
        '^concessionari/provincia-([^/]+)/?$',
        'index.php?post_type=concessionario&concessionario_provincia=$matches[1]',
        'top'
    );

    // Scuola + Provincia
    add_rewrite_rule(
        '^concessionari/scuola-([^/]+)/provincia-([^/]+)/?$',
        'index.php?post_type=concessionario&concessionario_scuola=$matches[1]&concessionario_provincia=$matches[2]',
        'top'
    );
});

add_action('pre_get_posts', function($query) {
    if (!is_admin() && $query->is_main_query() && is_post_type_archive('concessionario')) {

        $scuola_slug    = get_query_var('concessionario_scuola');
        $provincia_slug = get_query_var('concessionario_provincia');

        if (!$scuola_slug || !$provincia_slug) return;

        $scuola_term    = get_term_by('slug', $scuola_slug, 'concessionario_scuola');
        $provincia_term = get_term_by('slug', $provincia_slug, 'concessionario_provincia');

        if (!$scuola_term || !$provincia_term) return;

        $scuola_id    = (int) $scuola_term->term_id;
        $provincia_id = (int) $provincia_term->term_id;

        // Recupera tutti i post "concessionario"
        $matching_ids = [];

        $args = [
            'post_type'      => 'concessionario',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        $posts = get_posts($args);

        foreach ($posts as $post_id) {
            $classi_sconto = get_field('classi_sconto', $post_id);

            if (!$classi_sconto || !is_array($classi_sconto)) continue;

            foreach ($classi_sconto as $classe) {
                if ((int) $classe['scuola'] !== $scuola_id) continue;
                if (empty($classe['zone'])) continue;

                foreach ($classe['zone'] as $zona) {
                    if ((int) $zona['provincia'] === $provincia_id) {
                        $matching_ids[] = $post_id;
                        break 2; // match trovato, passa al prossimo post
                    }
                }
            }
        }

        // Applica il filtro alla query principale
        if (!empty($matching_ids)) {
            $query->set('post__in', $matching_ids);
        } else {
            // Nessun risultato: evita query inutile
            $query->set('post__in', [0]);
        }

        // Lascia visibile anche la tassonomia per breadcrumb / URL
        $query->set('tax_query', [
            [
                'taxonomy' => 'concessionario_scuola',
                'field'    => 'slug',
                'terms'    => $scuola_slug,
            ],
            [
                'taxonomy' => 'concessionario_provincia',
                'field'    => 'slug',
                'terms'    => $provincia_slug,
            ],
        ]);
    }
});


add_filter('query_vars', function($vars) {
    $vars[] = 'concessionario_scuola';
    $vars[] = 'concessionario_provincia';
    return $vars;
});

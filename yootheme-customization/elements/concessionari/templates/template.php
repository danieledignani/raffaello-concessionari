<?php

    $el = $this->el('div');
    echo $el($props, $attrs);

    if ($props['content']) {

        //echo $props['content'];
        $classi_sconto = explode(',', $props['content']);
        //se classi sconto non è vuoto crea un'iterazione per stampare le classi sconto
        if (!empty($classi_sconto)) {
            $classi_sconto_splitted = [];
            foreach ($classi_sconto as $classe_sconto) {
                $classe_sconto_splitted = explode('|', trim($classe_sconto));
                $scuola = array_shift($classe_sconto_splitted);
                $regione = array_shift($classe_sconto_splitted);
                $provincia_sigla = array_shift($classe_sconto_splitted);
                $provincia = array_shift($classe_sconto_splitted);
                $tipo = array_shift($classe_sconto_splitted);
                // se tutti i termini non sono nulli esegui
                if($scuola && $regione && $provincia_sigla && $provincia && $tipo) {
                    $classi_sconto_splitted[$scuola][$regione.'|'.$provincia_sigla.'|'.$provincia][] = $tipo;
                }
            }

            //recupero i filtri attivi
            $filterScuolaValues = [];
            $filterProvinceValues = [];
            if(function_exists('flrt_selected_filter_terms')) {
                $concessionariArchivePageFilters = flrt_selected_filter_terms();
                if($concessionariArchivePageFilters){
                    foreach ($concessionariArchivePageFilters as $filter) {
                        $filterType = $filter['e_name'];
                        if($filterType == 'concessionario_scuola'){
                            $filterScuolaValues = $filter['values'];
                        }
                        if($filterType == 'concessionario_provincia'){
                            $filterProvinceValues = $filter['values'];
                        }
                    }
                }
            }
            
            foreach ($classi_sconto_splitted as $scuola => $province) {
                //scuola dell'infanzia non deve essere visualizzata
                if($scuola == 'Infanzia' || (!empty($filterScuolaValues) && !in_array(strtolower($scuola), $filterScuolaValues)) ){
                    continue;
                }

                switch($scuola) {
                    case 'Infanzia':
                        $scuola = 'Scuola dell\'Infanzia';
                        break;
                    case 'Primaria':
                        $scuola = 'Scuola Primaria';
                        break;
                    case 'Secondaria':
                        $scuola = 'Scuola Secondaria';
                        break;
                }

                if(!empty($province)){
                    $result = '';
                    foreach ($province as $localita => $tipi) {
                        $localita_splitted = explode('|', $localita);
                        $regione = $localita_splitted[0];
                        $provincia_sigla = $localita_splitted[1];
                        $provincia = $localita_splitted[2];
                        $is_filtered_provincia = in_array(strtolower($provincia_sigla), $filterProvinceValues);
                        $is_filtered_regione = in_array(rc_get_region_value($regione), $filterProvinceValues);
                        if(!empty($filterProvinceValues) && ( !$is_filtered_provincia && !$is_filtered_regione)){
                            continue;
                        }
                        $result = $result.'<div class="provincia">' . $provincia;
                        foreach ($tipi as $tipo) {
                            $result = $result.'<span class="tipo '.strtolower($tipo).'">' . $tipo . '</span>';
                        }
                        $result = $result.'</div>';
                    }
                    if($result){
                        echo '<article class="scuola-row">';
                        echo '<div class="scuola" aria-label="Tipo di scuola">' . $scuola . '</div>';
                        echo '<div class="province">';
                        echo $result;
                        echo '</div>';
                        echo '</article>';
                    }
                }
            }
        }
    }

    echo $el->end();

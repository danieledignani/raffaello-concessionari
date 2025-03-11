<?php

    $el = $this->el('div');
    echo $el($props, $attrs);

    if ($props['content']) {
        $email = $props['content'];
        if(function_exists('flrt_selected_filter_terms')) {
            $concessionariArchivePageFilters = flrt_selected_filter_terms();
            if($concessionariArchivePageFilters){
                foreach ($concessionariArchivePageFilters as $filter) {
                    $filterType = $filter['e_name'];
                    if($filterType == 'concessionario_scuola'){
                        $filterScuolaValues = $filter['values'];
                    }
                }
            }
            if((!empty($filterScuolaValues ) && count($filterScuolaValues) == 1) && $props['label']) {
                $slug = $props['slug_scuola_match'];
                if($slug && in_array($slug, $filterScuolaValues)){
                    echo '<div class="email-row"><div class="label"><strong>Email:</strong></div><div class="email">'.$email.'</div></div>';
                }
            }

            // $label = 'Email';
            // if((empty($filterScuolaValues ) || count($filterScuolaValues) > 1) && $props['label']) {
            //     $label = $label.' per scuola '.$props['label'];
            // }
            // if(!empty($filterScuolaValues )){
            //     $slug = $props['slug_scuola_match'];
            //     if($slug && in_array($slug, $filterScuolaValues)){
            //         echo '<div class="email-row"><div class="label"><strong>'.$label.':</strong></div><div class="email">'.$email.'</div></div>';
            //     }
            // } else {
            //     echo '<div class="email-row"><div class="label"><strong>'.$label.':</strong></div><div class="email">'.$email.'</div></div>';
            // }
        }
    }


    echo $el->end();

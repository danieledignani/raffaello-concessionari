<?php
// Creo log personalizzato per tener traccia delle chiamate rest api
function rc_concessionari_log($text_log, $object = null) {
    $logger_array = array('source' => 'concessionari');

    // Prepara il testo da loggare
    $object_text = is_null($object) ? '' : wc_print_r($object, true);
    
    wc_get_logger()->info($text_log . $object_text, $logger_array);
}
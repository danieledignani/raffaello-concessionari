document.addEventListener('DOMContentLoaded', function () {
    // Ottieni l'URL corrente
    const currentUrl = window.location.href;
    // Controlla se l'URL contiene una parte specifica
    if (currentUrl.includes("filtro-conc_scuola-primaria") || currentUrl.includes("filtro-conc_scuola-secondaria") || currentUrl.includes("filtro-conc_scuola-infanzia")) {
        document.querySelector('#concessionari-filtro-per-scuola').style.display = 'none';
        document.querySelector('#concessionari-list').style.display = 'flex';
    } else {
        document.querySelector('#concessionari-filtro-per-scuola').style.display = 'flex';
        document.querySelector('#concessionari-list').style.display = 'none';
        // con jquery seleziona il bottone #concessionari-filtro-per-scuola .wpc-open-close-filters-button, simula il click
        // Verifica periodica per assicurarti che il plugin abbia caricato tutto
        const waitForElements = setInterval(function() {
            const button = jQuery('#concessionari-filtro-per-scuola .wpc-open-close-filters-button');
            const buttonClosed = jQuery('#concessionari-filtro-per-scuola .wpc-open-close-filters-button.wpc-closed');
            const buttonOpened = jQuery('#concessionari-filtro-per-scuola .wpc-open-close-filters-button.wpc-opened');
            const container = jQuery('#concessionari-filtro-per-scuola .wpc-filters-open-button-container');

            // Verifica se gli elementi sono pronti
            if (button.length && container.length && (buttonClosed.length || buttonOpened.length)) {
                if(buttonClosed.length){
                    button.click();
                }
                container.hide();
                clearInterval(waitForElements); // Ferma l'intervallo una volta che è tutto pronto
            }
        }, 100); // Controlla ogni 100 millisecondi

    }
});

function hideSectionOnRadioChange() {
    // Seleziona tutti i radio buttons
    jQuery('input[type="radio"]').on('change', function() {
        if (jQuery(this).is(':checked')) {
            // Nascondi la sezione che contiene il radio button
            jQuery(this).closest('#concessionari-filtro-per-scuola').hide();
        }
    });
    if(jQuery('#concessionari-filtro-per-scuola input[type="radio"]').is(':checked')) {
        jQuery('#concessionari-filtro-per-scuola').hide();
    }
}

// Esegui al caricamento iniziale della pagina
jQuery(document).ready(function() {
    hideSectionOnRadioChange();
});

// Forza l'esecuzione dello script dopo il caricamento AJAX
jQuery(document).on('ready', function() {
    hideSectionOnRadioChange();
});

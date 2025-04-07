<?php
use YOOtheme\Application;
/**
 * Plugin Name: Raffaello Concessionari
 * Plugin URI: https://raffaelloscuola.it
 * Description: Gestione dei concessionari e classi di sconto.
 * Version: 5.0
 */

// Impedisce l'accesso diretto ai file del plugin
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Definisci le costanti per il plugin
define('CONCESSIONARI_PLUGIN_VERSION', '5.0');
define('CONCESSIONARI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONCESSIONARI_PLUGIN_URL', plugin_dir_url(__FILE__));

class ConcessionariPlugin {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->include_files();
        $this->init_hooks();
    }

    private function include_files() {
        include_once CONCESSIONARI_PLUGIN_DIR . 'includes/rest-api.php';
        include_once CONCESSIONARI_PLUGIN_DIR . 'includes/logger.php';
        include_once CONCESSIONARI_PLUGIN_DIR . 'includes/province-handler.php';
        include_once CONCESSIONARI_PLUGIN_DIR . 'includes/archive-filter.php';
    }

    private function init_hooks() {
        add_action('rest_api_init', array($this, 'register_rest_api'), 10);
        add_action('after_setup_theme', array($this, 'register_concessionari_template_in_yootheme'), 10);
        
    }
    public function custom_archive_concessionari_css() {
        if (is_post_type_archive('concessionari')) { // Controlla se siamo nell'archivio del custom post type "concessionari"
            wp_enqueue_style('concessionari-css', plugin_dir_url(__FILE__) . 'css/concessionari.css');
        }
    }
    
    public function register_rest_api() {
        rc_register_concessionari_rest_api_add();
    }

    public function register_concessionari_template_in_yootheme() {
        // Check if YOOtheme Pro is loaded
        if (!class_exists(Application::class, false)) {
            return;
        }

        // Load a single module from the same directory
        $app = Application::getInstance();
        $app->load(__DIR__ . '/yootheme-customization/bootstrap.php');
    }

}

add_action('plugins_loaded', function() {
    ConcessionariPlugin::instance();
});

// Auto-update via Plugin Update Checker
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/danieledignani/Raffaello-concessionari-json/main/raffaello-concessionari.json',
    __FILE__,
    'raffaello-concessionari'
);

// Sposta l'autenticazione dopo che ACF è sicuramente caricato
add_action('init', function () use ($updateChecker) {
    if (function_exists('get_field')) {
        $github_token = get_field('github_token', 'option');
        if (!empty($github_token)) {
            $updateChecker->setAuthentication($github_token);
        }
    }
});

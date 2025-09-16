<?php

/**
 * Plugin Name: Staging Theme
 * Description: Permette di creare più versioni di staging di un tema e attivarle tramite parametro nell'URL
 * Version: 1.2.2
 * Author: SP Studio
 * Changelog: Aggiunto supporto robusto per richieste AJAX/REST.
 */

// Previeni l'accesso diretto al file
if (!defined('ABSPATH')) {
    exit;
}

class Staging_Theme {

    // Costruisce il nome della directory di staging
    private function get_staging_dir($theme_slug, $version) {
        return $theme_slug . '-staging-' . $version;
    }

    // Parametro URL per attivare il tema di staging
    private $url_param = 'staging';

    // Costruttore
    public function __construct($skip_hooks = false) {
        if ($skip_hooks) {
            return;
        }
        // Aggiunge i filtri per cambiare il tema quando è presente il parametro
        add_filter('template', array($this, 'switch_template'));
        add_filter('stylesheet', array($this, 'switch_stylesheet'));

    // Inizializza il contesto di staging anche per richieste AJAX/REST (eseguito molto presto)
    add_action('init', array($this, 'bootstrap_staging_context'), 1);
        // Includi subito la logica per le chiamate AJAX (prima di tutto)
        self::include_staging_ajax_file();

        // Aggiungi menu in amministrazione
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Registra le impostazioni
        add_action('admin_init', array($this, 'register_settings'));

        // Aggiungi AJAX handler per eliminare i temi di staging
        add_action('wp_ajax_delete_staging_theme', array($this, 'ajax_delete_staging_theme'));
        
        // Aggiungi AJAX handler per rimuovere un tema dall'elenco
        add_action('wp_ajax_remove_staging_theme_from_list', array($this, 'ajax_remove_staging_theme_from_list'));

    // Endpoint di debug per verificare il contesto di staging nelle richieste AJAX
    add_action('wp_ajax_staging_debug', array($this, 'ajax_staging_debug'));
    add_action('wp_ajax_nopriv_staging_debug', array($this, 'ajax_staging_debug'));
    }
    
    /**
     * Includi ajax.php dal tema di staging in tutte le richieste AJAX se il parametro staging è presente.
     * Usa la logica già esistente per identificare versione e directory.
     */
    public static function include_staging_ajax_file() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (function_exists('error_log')) {
                error_log('[STAGING AJAX] Entrato in include_staging_ajax_file');
            }
            // Recupera la versione di staging da REQUEST, Referer, cookie (come la logica di bootstrap)
            $version = null;
            if (isset($_REQUEST['staging']) && $_REQUEST['staging'] !== '') {
                $version = sanitize_title($_REQUEST['staging']);
            } else {
                // Prova dal Referer
                $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
                if (!empty($referer)) {
                    $q = parse_url($referer, PHP_URL_QUERY);
                    if (!empty($q)) {
                        parse_str($q, $queryParams);
                        if (!empty($queryParams['staging'])) {
                            $version = sanitize_title($queryParams['staging']);
                        }
                    }
                }
                // Fallback: cookie
                if (empty($version) && isset($_COOKIE['staging_version']) && $_COOKIE['staging_version'] !== '') {
                    $version = sanitize_title($_COOKIE['staging_version']);
                }
            }
            if (function_exists('error_log')) {
                error_log('[STAGING AJAX] Version rilevata: ' . var_export($version, true));
            }
            if ($version) {
                $instance = new self(true);
                $exists = $instance->staging_theme_exists($version);
                if (function_exists('error_log')) {
                    error_log('[STAGING AJAX] staging_theme_exists: ' . ($exists ? 'true' : 'false'));
                }
                if ($exists) {
                    $staging_theme_path = $instance->get_staging_theme_path($version);
                    $ajax_file = rtrim($staging_theme_path, '/').'/ajax.php';
                    if (function_exists('error_log')) {
                        error_log('[STAGING AJAX] ajax_file path: ' . $ajax_file . ' | exists: ' . (file_exists($ajax_file) ? 'true' : 'false'));
                    }
                    if (file_exists($ajax_file)) {
                        include_once $ajax_file;
                        if (function_exists('error_log')) {
                            error_log('[STAGING AJAX] ajax.php incluso!');
                        }
                    }
                }
            }
        }
    }

    /**
     * Imposta il contesto di staging in modo robusto per tutte le richieste rilevanti.
     * - Frontend: se presente ?staging= in URL, salva un cookie per uso futuro (AJAX)
     * - AJAX/REST: se manca il parametro nella richiesta, prova a recuperarlo dal cookie
     * Quindi imposta $_GET['staging'] così che i filtri 'template' e 'stylesheet' possano funzionare.
     */
    public function bootstrap_staging_context() {
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
        $is_rest = defined('REST_REQUEST') && REST_REQUEST;

        // 1) Se l'URL corrente ha già il parametro staging, aggiorna il cookie (utile per future chiamate AJAX)
        if (isset($_GET[$this->url_param]) && $_GET[$this->url_param] !== '') {
            $version = sanitize_title($_GET[$this->url_param]);

            // Aggiorna cookie solo se la versione è valida (tema di staging esistente)
            if ($this->staging_theme_exists($version)) {
                $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
                $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                $expire = time() + (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400) * 7; // 7 giorni
                // Imposta cookie disponibile su tutto il sito
                setcookie('staging_version', $version, $expire, $cookie_path, $cookie_domain, is_ssl(), true);
            }
            return; // Non sovrascrivere nulla: abbiamo già il parametro esplicito nell'URL
        }

        // 2) Per richieste AJAX/REST: propaga staging da REQUEST o Referer; evita di usare il cookie se la pagina non è in contesto staging
        if ($is_ajax || $is_rest) {
            $version = null;

            // a) Parametro esplicito nella richiesta (GET/POST)
            if (isset($_REQUEST[$this->url_param]) && $_REQUEST[$this->url_param] !== '') {
                $version = sanitize_title($_REQUEST[$this->url_param]);
            } else {
                // b) Prova a leggere dal Referer della pagina che ha originato la chiamata
                $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
                if (!empty($referer)) {
                    $q = parse_url($referer, PHP_URL_QUERY);
                    if (!empty($q)) {
                        parse_str($q, $queryParams);
                        if (!empty($queryParams[$this->url_param])) {
                            $version = sanitize_title($queryParams[$this->url_param]);
                        }
                    }
                }

                // c) Solo se il Referer non è disponibile ma il cookie esiste, usa il cookie come fallback prudente
                if (empty($version) && isset($_COOKIE['staging_version']) && $_COOKIE['staging_version'] !== '') {
                    $version = sanitize_title($_COOKIE['staging_version']);
                }
            }

            if (!empty($version) && $this->staging_theme_exists($version)) {
                $_GET[$this->url_param] = $version;
            }
        } else {
            // 3) Navigazione normale senza parametro: rimuovi il cookie per evitare effetti collaterali su richieste future
            if (!isset($_GET[$this->url_param]) && isset($_COOKIE['staging_version'])) {
                $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
                $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                setcookie('staging_version', '', time() - 3600, $cookie_path, $cookie_domain, is_ssl(), true);
            }
        }
    }

    // Duplica il tema
    public function duplicate_theme() {
        // Verifica nonce per sicurezza
        check_admin_referer('staging_theme_nonce', 'staging_theme_nonce');

        // Ottieni il nome della versione di staging
        $staging_version = sanitize_title($_POST['staging_version']);

        if (empty($staging_version)) {
            add_settings_error(
                'staging_theme',
                'staging_theme_error',
                'È necessario specificare un nome per la versione di staging.',
                'error'
            );
            return false;
        }

        $current_theme = wp_get_theme();
        $theme_dir = get_template_directory();
        $theme_name = $current_theme->get('Name');
        $theme_slug = get_option('stylesheet');

        // Percorso del tema di staging
        $staging_theme_dir = WP_CONTENT_DIR . '/themes/' . $theme_slug . '-staging-' . $staging_version;

        // Se la cartella di staging esiste già, non mostriamo un errore ma riconosciamo il tema esistente
        if (file_exists($staging_theme_dir)) {
            // Controlla se questa versione è già nell'elenco
            $staging_versions = $this->get_staging_versions();
            if (!in_array($staging_version, $staging_versions)) {
                // Se non è nell'elenco, aggiungiamola
                $staging_versions[] = $staging_version;
                update_option('staging_theme_versions', $staging_versions);
                
                add_settings_error(
                    'staging_theme',
                    'staging_theme_success',
                    'Tema di staging esistente riconosciuto! Puoi accedere alla versione di staging aggiungendo ?staging=' . $staging_version . ' all\'URL.',
                    'success'
                );
            } else {
                add_settings_error(
                    'staging_theme',
                    'staging_theme_info',
                    'Questa versione di staging è già registrata. Puoi accedere alla versione aggiungendo ?staging=' . $staging_version . ' all\'URL.',
                    'info'
                );
            }
            return true;
        }

        // Crea la cartella di staging
        if (!mkdir($staging_theme_dir, 0755, true)) {
            add_settings_error(
                'staging_theme',
                'staging_theme_error',
                'Impossibile creare la cartella del tema di staging.',
                'error'
            );
            return false;
        }

        // Copia tutti i file dal tema originale a quello di staging
        $this->copy_directory($theme_dir, $staging_theme_dir);

        // Modifica il nome del tema nel file style.css
        $style_css = $staging_theme_dir . '/style.css';
        if (file_exists($style_css)) {
            $css_content = file_get_contents($style_css);
            // Aggiorna il nome del tema
            $css_content = preg_replace('/Theme Name:(.*)/', 'Theme Name:$1 (Staging: ' . $staging_version . ')', $css_content, 1);
            file_put_contents($style_css, $css_content);
        }

        // Salva la lista delle versioni di staging
        $staging_versions = $this->get_staging_versions();
        $staging_versions[] = $staging_version;
        update_option('staging_theme_versions', $staging_versions);

        add_settings_error(
            'staging_theme',
            'staging_theme_success',
            'Tema duplicato con successo! Puoi accedere alla versione di staging aggiungendo ?staging=' . $staging_version . ' all\'URL.',
            'success'
        );

        return true;
    }

    // Ottieni tutte le versioni di staging
    public function get_staging_versions() {
        $versions = get_option('staging_theme_versions', array());
        return is_array($versions) ? $versions : array();
    }

    // Verifica se il tema di staging esiste fisicamente
    public function staging_theme_exists($version) {
        $current_stylesheet = get_option('stylesheet');
        $staging_theme_dir = WP_CONTENT_DIR . '/themes/' . $this->get_staging_dir($current_stylesheet, $version);
        return file_exists($staging_theme_dir);
    }

    // Elimina una directory e il suo contenuto
    private function delete_directory($dir) {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!$this->delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    // Copia una directory e il suo contenuto
    private function copy_directory($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);

        while (($file = readdir($dir)) !== false) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->copy_directory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }

    // Cambia il template quando è presente il parametro staging nell'URL
    public function switch_template($template) {
        if (isset($_GET[$this->url_param]) && !empty($_GET[$this->url_param])) {
            $staging_version = sanitize_title($_GET[$this->url_param]);
            $current_theme = get_option('stylesheet');
            $staging_template = $this->get_staging_dir($current_theme, $staging_version);

            // Verifica se esiste il tema di staging
            if (file_exists(WP_CONTENT_DIR . '/themes/' . $staging_template)) {
                return $staging_template;
            }
        }

        return $template;
    }

    // Cambia lo stylesheet quando è presente il parametro staging nell'URL
    public function switch_stylesheet($stylesheet) {
        if (isset($_GET[$this->url_param]) && !empty($_GET[$this->url_param])) {
            $staging_version = sanitize_title($_GET[$this->url_param]);
            $current_stylesheet = get_option('stylesheet');
            $staging_stylesheet = $this->get_staging_dir($current_stylesheet, $staging_version);

            // Verifica se esiste il tema di staging
            if (file_exists(WP_CONTENT_DIR . '/themes/' . $staging_stylesheet)) {
                return $staging_stylesheet;
            }
        }

        return $stylesheet;
    }

    // Elimina un tema di staging tramite AJAX
    public function ajax_delete_staging_theme() {
        // Verifica nonce
        check_ajax_referer('delete_staging_theme', 'security');

        // Verifica permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }

        $version = sanitize_title($_POST['version']);

        if (empty($version)) {
            wp_send_json_error('Versione non valida');
        }

        // Percorso del tema di staging
        $staging_theme_dir = WP_CONTENT_DIR . '/themes/' . $this->get_staging_dir(get_option('stylesheet'), $version);

        // Verifica se esiste
        if (!file_exists($staging_theme_dir)) {
            // Se non esiste fisicamente, rimuovi solo dalla lista
            $this->remove_from_versions_list($version);
            wp_send_json_success('Tema rimosso dalla lista con successo');
            return;
        }

        // Elimina la directory
        if (!$this->delete_directory($staging_theme_dir)) {
            wp_send_json_error('Impossibile eliminare il tema di staging');
        }

        // Rimuovi dalla lista
        $this->remove_from_versions_list($version);

        wp_send_json_success('Tema di staging eliminato con successo');
    }

    // Rimuovi un tema solo dalla lista senza eliminare i file
    public function ajax_remove_staging_theme_from_list() {
        // Verifica nonce
        check_ajax_referer('remove_staging_theme', 'security');

        // Verifica permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }

        $version = sanitize_title($_POST['version']);

        if (empty($version)) {
            wp_send_json_error('Versione non valida');
        }

        // Rimuovi dalla lista
        $this->remove_from_versions_list($version);

        wp_send_json_success('Tema rimosso dalla lista con successo');
    }

    /**
     * Endpoint AJAX di debug: restituisce informazioni sul tema attivo
     * e sulla versione di staging rilevata in questa richiesta.
     */
    public function ajax_staging_debug() {
        // Non forziamo nonce per debug, ma limitiamo l'output
        $detected_staging = isset($_GET[$this->url_param]) ? sanitize_title($_GET[$this->url_param]) : null;
        $data = array(
            'is_ajax' => (defined('DOING_AJAX') && DOING_AJAX),
            'template' => get_option('template'),
            'stylesheet' => get_option('stylesheet'),
            'stylesheet_dir' => function_exists('get_stylesheet_directory') ? get_stylesheet_directory() : null,
            'detected_staging_param' => $detected_staging,
        );
        wp_send_json_success($data);
    }

    // Funzione per rimuovere un tema dalla lista
    private function remove_from_versions_list($version) {
        $staging_versions = $this->get_staging_versions();
        $key = array_search($version, $staging_versions);
        if ($key !== false) {
            unset($staging_versions[$key]);
            update_option('staging_theme_versions', array_values($staging_versions));
            return true;
        }
        return false;
    }

    // Aggiunge una pagina di amministrazione
    public function add_admin_menu() {
        add_theme_page(
            'Staging Theme',
            'Staging Theme',
            'manage_options',
            'staging-theme',
            array($this, 'admin_page')
        );
    }

    // Registra le impostazioni
    public function register_settings() {
        register_setting('staging_theme', 'staging_theme_options');
    }
    
    /**
     * Restituisce l'ID completo (nome cartella) di un tema di staging
     * 
     * @param string $version La versione del tema di staging
     * @return string L'ID completo del tema di staging
     */
    public function get_staging_theme_id($version) {
        $theme_slug = get_option('stylesheet');
        return $this->get_staging_dir($theme_slug, $version);
    }

    /**
     * Rileva il tipo di hosting
     * 
     * @return string Tipo di hosting (siteground, plesk, cpanel, ecc.)
     */
    public function detect_hosting_type() {
        // Cerca nell'ABSPATH elementi che possano identificare l'hosting
        $abspath = ABSPATH;
        
        if (strpos($abspath, '/www/') !== false && strpos($abspath, '/public_html/') !== false) {
            return 'siteground';
        } elseif (strpos($abspath, '/vhosts/') !== false && strpos($abspath, '/httpdocs/') !== false) {
            return 'plesk';
        } elseif (strpos($abspath, '/public_html/') !== false) {
            return 'cpanel';
        }
        
        // Default
        return 'standard';
    }
    
    /**
     * Restituisce il dominio del sito corrente
     * 
     * @return string Nome del dominio senza http/https
     */
    public function get_site_domain() {
        return str_replace(array('https://', 'http://'), '', site_url());
    }
    
    /**
     * Restituisce il percorso completo alla cartella del tema di staging sul server
     * 
     * @param string $version La versione del tema di staging
     * @param bool $ftp_path Se true, restituisce il percorso completo per FTP
     * @return string Il percorso alla cartella del tema
     */
    public function get_staging_theme_path($version, $ftp_path = false) {
        $theme_id = $this->get_staging_theme_id($version);
        
        if ($ftp_path) {
            // Percorso completo per FTP con considerazione del tipo di hosting
            $hosting_type = $this->detect_hosting_type();
            $document_root = $this->get_document_root_folder();
            $domain = $this->get_site_domain();
            
            if ($hosting_type === 'siteground') {
                // Per SiteGround: dominio/public_html/wp-content/themes/...
                return $domain . '/' . $document_root . '/wp-content/themes/' . $theme_id;
            } else {
                // Per Plesk e altri: httpdocs/wp-content/themes/...
                return $document_root . '/wp-content/themes/' . $theme_id;
            }
        } else {
            // Percorso assoluto completo
            return ABSPATH . 'wp-content/themes/' . $theme_id;
        }
    }
    
    /**
     * Restituisce la document root del server (cartella pubblica)
     * 
     * @return string Nome della cartella document root (es. "httpdocs", "public_html")
     */
    public function get_document_root_folder() {
        $abspath = ABSPATH;
        $parts = explode('/', rtrim($abspath, '/'));
        
        // La document root è generalmente l'ultima cartella nel percorso ABSPATH
        return end($parts);
    }

    // Pagina di amministrazione
    public function admin_page() {
        // Gestisci l'azione di duplicazione
        if (isset($_POST['duplicate_theme']) && isset($_POST['staging_version'])) {
            $this->duplicate_theme();
        }

        $current_theme = wp_get_theme();
        $staging_versions = $this->get_staging_versions();

?>
        <div class="wrap">
            <h1>Staging Theme</h1>

            <div class="card">
                <h2>Tema attuale: <?php echo esc_html($current_theme->get('Name')); ?></h2>

                <form method="post" action="">
                    <?php wp_nonce_field('staging_theme_nonce', 'staging_theme_nonce'); ?>
                    <p>Crea una nuova versione di staging del tema attuale o registra una versione esistente. Ogni versione di staging è accessibile aggiungendo il parametro "staging" all'URL del sito.</p>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="staging_version">Nome versione di staging</label></th>
                                <td>
                                    <input name="staging_version" type="text" id="staging_version" value="" class="regular-text">
                                    <p class="description">Un nome univoco per identificare questa versione (es. "test-header", "nuovo-footer")</p>
                                    <p class="description">Se la cartella del tema già esiste, verrà riconosciuta senza duplicare il tema.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p>
                        <input type="submit" name="duplicate_theme" class="button button-primary" value="Crea/Registra versione di staging">
                    </p>
                </form>
            </div>

            <?php if (!empty($staging_versions)): ?>
                <h2 style="margin-top: 30px;">Versioni di staging esistenti</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Versione</th>
                            <th>ID Tema</th>
                            <th>URL di accesso</th>
                            <th>Percorso FTP</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staging_versions as $version): 
                            $theme_exists = $this->staging_theme_exists($version);
                            $theme_path = $this->get_staging_theme_path($version);
                        ?>
                            <tr<?php if (!$theme_exists): ?> class="error"<?php endif; ?>>
                                <td>
                                    <?php echo esc_html($version); ?>
                                    <?php if (!$theme_exists): ?>
                                        <div class="row-actions">
                                            <span class="error" style="color: #dc3232;">Il tema è stato eliminato manualmente</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($this->get_staging_theme_id($version)); ?>
                                </td>
                                <td>
                                    <?php if ($theme_exists): ?>
                                        <a href="<?php echo esc_url(home_url('?staging=' . $version)); ?>" target="_blank">
                                            <?php echo esc_url(home_url('?staging=' . $version)); ?>
                                        </a>
                                    <?php else: ?>
                                        <em>Non disponibile</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($theme_exists): 
                                        $ftp_path = $this->get_staging_theme_path($version, true);
                                        $hosting_type = $this->detect_hosting_type();
                                    ?>
                                        <div class="copy-path-container">
                                            <code class="path-code"><?php echo esc_html($ftp_path); ?></code>
                                            <button type="button" class="button copy-path-button" data-path="<?php echo esc_attr($ftp_path); ?>">
                                                <span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> Copia
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <em>Non disponibile</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($theme_exists): ?>
                                    <button class="button delete-staging-theme" data-version="<?php echo esc_attr($version); ?>" data-nonce="<?php echo wp_create_nonce('delete_staging_theme'); ?>">Elimina</button>
                                    <?php else: ?>
                                        <button class="button remove-staging-theme" data-version="<?php echo esc_attr($version); ?>" data-nonce="<?php echo wp_create_nonce('remove_staging_theme'); ?>">Rimuovi dall'elenco</button>
                                    <?php endif; ?>
                                </td>
                                </tr>
                            <?php endforeach; ?>
                    </tbody>
                </table>

                <style>
                    .copy-path-container {
                        display: flex;
                        align-items: center;
                    }
                    .path-code {
                        flex: 1;
                        margin-right: 10px;
                        background: #f0f0f1;
                        padding: 5px;
                        border-radius: 3px;
                        word-break: break-all;
                        font-size: 12px;
                    }
                    .copy-path-button {
                        flex-shrink: 0;
                        display: flex;
                        align-items: center;
                        margin-right: 8px;
                    }
                    .copy-path-button .dashicons {
                        margin-right: 3px;
                    }
                </style>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Gestisce la copia del percorso negli appunti
                        $('.copy-path-button').on('click', function() {
                            var pathText = $(this).data('path');
                            var button = $(this);
                            var originalText = button.html();
                            
                            // Crea un elemento di testo temporaneo per copiare il testo
                            var tempInput = $('<textarea>');
                            $('body').append(tempInput);
                            tempInput.val(pathText).select();
                            
                            try {
                                // Esegui il comando di copia
                                document.execCommand('copy');
                                
                                // Feedback all'utente
                                button.html('<span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Copiato!');
                                
                                // Ripristina il testo del pulsante dopo 2 secondi
                                setTimeout(function() {
                                    button.html(originalText);
                                }, 2000);
                            } catch (err) {
                                alert('Impossibile copiare il percorso: ' + err);
                            }
                            
                            // Rimuovi l'elemento temporaneo
                            tempInput.remove();
                        });

                        // Gestisce l'eliminazione fisica del tema
                        $('.delete-staging-theme').on('click', function(e) {
                            e.preventDefault();

                            if (!confirm('Sei sicuro di voler eliminare questa versione di staging?')) {
                                return;
                            }

                            var button = $(this);
                            var version = button.data('version');
                            var nonce = button.data('nonce');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'delete_staging_theme',
                                    version: version,
                                security: nonce
                                },
                                before_send: function() {
                                    button.prop('disabled', true).text('Eliminazione...');
                                },
                                success: function(response) {
                                    if (response.success) {
                                        button.closest('tr').fadeOut(400, function() {
                                            $(this).remove();
                                        });
                                    } else {
                                    alert('Errore: ' + response.data);
                                    button.prop('disabled', false).text('Elimina');
                                }
                            },
                            error: function() {
                                alert('Si è verificato un errore durante l\'eliminazione.');
                                button.prop('disabled', false).text('Elimina');
                                }
                            });
                        });

                        // Gestisce la rimozione dall'elenco (quando il tema è già stato eliminato manualmente)
                        $('.remove-staging-theme').on('click', function(e) {
                            e.preventDefault();

                            if (!confirm('Sei sicuro di voler rimuovere questa versione dall\'elenco?')) {
                                return;
                            }

                            var button = $(this);
                            var version = button.data('version');
                            var nonce = button.data('nonce');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'remove_staging_theme_from_list',
                                    version: version,
                                    security: nonce
                                },
                                before_send: function() {
                                    button.prop('disabled', true).text('Rimozione...');
                                },
                                success: function(response) {
                                    if (response.success) {
                                        button.closest('tr').fadeOut(400, function() {
                                            $(this).remove();
                                        });
                                    } else {
                                        alert('Errore: ' + response.data);
                                        button.prop('disabled', false).text('Rimuovi dall\'elenco');
                                    }
                                },
                                error: function() {
                                    alert('Si è verificato un errore durante la rimozione.');
                                    button.prop('disabled', false).text('Rimuovi dall\'elenco');
                                }
                            });
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
<?php
    }
}

// Aggiungi script per mantenere il parametro staging nelle URL
add_action('wp_enqueue_scripts', function() {
    // Script principale per modificare i link e le navigazioni
    wp_enqueue_script('staging-theme-sticky', plugin_dir_url(__FILE__) . 'js/staging-sticky.js', array(), '2.0', true);
    
    // Intercettatore universale di click e navigazioni
    wp_enqueue_script('staging-click-interceptor', plugin_dir_url(__FILE__) . 'js/staging-click-interceptor.js', array(), '1.0', true);
    
    // Priorità massima per garantire che sia l'ultimo script caricato
    wp_script_add_data('staging-click-interceptor', 'group', 1);
});

// Funzione helper per aggiungere il parametro staging a qualsiasi URL generato da PHP
function staging_theme_add_param_to_url($url) {
    // Ottieni la versione di staging corrente
    $version = null;
    
    // Controlla prima i parametri GET
    if (isset($_GET['staging']) && $_GET['staging'] !== '') {
        $version = sanitize_title($_GET['staging']);
    } 
    // Altrimenti controlla il cookie
    else if (isset($_COOKIE['staging_version']) && $_COOKIE['staging_version'] !== '') {
        $version = sanitize_title($_COOKIE['staging_version']);
    }
    
    // Se non c'è versione o l'URL è vuoto, restituisci l'URL originale
    if (empty($version) || empty($url)) {
        return $url;
    }
    
    // Non modificare URL che puntano all'admin
    if (strpos($url, '/wp-admin/') !== false) {
        return $url;
    }
    
    // Controlla se l'URL ha già il parametro staging
    if (strpos($url, 'staging=' . $version) !== false) {
        return $url; // Il parametro esiste già
    }
    
    // Aggiungi il parametro staging all'URL
    $separator = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $separator . 'staging=' . $version;
}

// Filtri per aggiungere il parametro staging a tutti gli URL generati da WordPress
add_filter('page_link', 'staging_theme_add_param_to_url', 99);
add_filter('post_link', 'staging_theme_add_param_to_url', 99);
add_filter('term_link', 'staging_theme_add_param_to_url', 99);
add_filter('attachment_link', 'staging_theme_add_param_to_url', 99);
add_filter('year_link', 'staging_theme_add_param_to_url', 99);
add_filter('month_link', 'staging_theme_add_param_to_url', 99);
add_filter('day_link', 'staging_theme_add_param_to_url', 99);
add_filter('search_link', 'staging_theme_add_param_to_url', 99);
add_filter('get_pagenum_link', 'staging_theme_add_param_to_url', 99);

// WooCommerce specifici
add_filter('woocommerce_get_cart_url', 'staging_theme_add_param_to_url', 99);
add_filter('woocommerce_get_checkout_url', 'staging_theme_add_param_to_url', 99);
add_filter('woocommerce_get_product_permalink', 'staging_theme_add_param_to_url', 99);

// Aggiungi gli stili Dashicons per i pulsanti
add_action('admin_enqueue_scripts', function($hook) {
    if ('appearance_page_staging-theme' === $hook) {
        wp_enqueue_style('dashicons');
    }
});

// Inizializza il plugin
$staging_theme = new Staging_Theme();
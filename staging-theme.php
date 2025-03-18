<?php

/**
 * Plugin Name: Staging Theme
 * Description: Permette di creare più versioni di staging di un tema e attivarle tramite parametro nell'URL
 * Version: 1.1.1-beta
 * Author: Daniel D'Antonio
 */

// Previeni l'accesso diretto al file
if (!defined('ABSPATH')) {
    exit;
}

class Staging_Theme
{

    // Costruisce il nome della directory di staging
    private function get_staging_dir($theme_slug, $version)
    {
        return $theme_slug . '-staging-' . $version;
    }

    // Parametro URL per attivare il tema di staging
    private $url_param = 'staging';

    // Costruttore
    public function __construct()
    {
        // Filtro per cambiare il tema quando è presente il parametro
        add_filter('template', array($this, 'switch_template'));
        add_filter('stylesheet', array($this, 'switch_stylesheet'));

        // Aggiungi menu in amministrazione
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Registra le impostazioni
        add_action('admin_init', array($this, 'register_settings'));

        // Aggiungi AJAX handler per eliminare i temi di staging
        add_action('wp_ajax_delete_staging_theme', array($this, 'ajax_delete_staging_theme'));
    }

    // Duplica il tema
    public function duplicate_theme()
    {
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

        // Se la cartella di staging esiste già, mostra un errore
        if (file_exists($staging_theme_dir)) {
            add_settings_error(
                'staging_theme',
                'staging_theme_error',
                'Una versione di staging con questo nome esiste già. Scegli un nome diverso.',
                'error'
            );
            return false;
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
    public function get_staging_versions()
    {
        $versions = get_option('staging_theme_versions', array());
        return is_array($versions) ? $versions : array();
    }

    // Elimina una directory e il suo contenuto
    private function delete_directory($dir)
    {
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
    private function copy_directory($src, $dst)
    {
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
    public function switch_template($template)
    {
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
    public function switch_stylesheet($stylesheet)
    {
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
    public function ajax_delete_staging_theme()
    {
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
            wp_send_json_error('Tema di staging non trovato');
        }

        // Elimina la directory
        if (!$this->delete_directory($staging_theme_dir)) {
            wp_send_json_error('Impossibile eliminare il tema di staging');
        }

        // Rimuovi dalla lista
        $staging_versions = $this->get_staging_versions();
        $key = array_search($version, $staging_versions);
        if ($key !== false) {
            unset($staging_versions[$key]);
            update_option('staging_theme_versions', array_values($staging_versions));
        }

        wp_send_json_success('Tema di staging eliminato con successo');
    }

    // Aggiunge una pagina di amministrazione
    public function add_admin_menu()
    {
        add_theme_page(
            'Staging Theme',
            'Staging Theme',
            'manage_options',
            'staging-theme',
            array($this, 'admin_page')
        );
    }

    // Registra le impostazioni
    public function register_settings()
    {
        register_setting('staging_theme', 'staging_theme_options');
    }

    // Pagina di amministrazione
    public function admin_page()
    {
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
                    <p>Crea una nuova versione di staging del tema attuale. Ogni versione di staging è accessibile aggiungendo il parametro "staging" all'URL del sito.</p>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="staging_version">Nome versione di staging</label></th>
                                <td>
                                    <input name="staging_version" type="text" id="staging_version" value="" class="regular-text">
                                    <p class="description">Un nome univoco per identificare questa versione (es. "test-header", "nuovo-footer")</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p>
                        <input type="submit" name="duplicate_theme" class="button button-primary" value="Crea nuova versione di staging">
                    </p>
                </form>
            </div>

            <?php if (!empty($staging_versions)): ?>
                <h2 style="margin-top: 30px;">Versioni di staging esistenti</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Versione</th>
                            <th>URL di accesso</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staging_versions as $version): ?>
                            <?php
                            $theme_slug = get_option('stylesheet');
                            $staging_theme_path = WP_CONTENT_DIR . '/themes/' . $this->get_staging_dir($theme_slug, $version);
                            $theme_exists = file_exists($staging_theme_path);
                            ?>
                            <tr<?php echo !$theme_exists ? ' class="staging-missing"' : ''; ?>>
                                <td><?php echo esc_html($version); ?></td>
                                <td>
                                    <?php if ($theme_exists): ?>
                                        <a href="<?php echo esc_url(home_url('?staging=' . $version)); ?>" target="_blank">
                                            <?php echo esc_url(home_url('?staging=' . $version)); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="staging-url-missing" title="La cartella del tema non esiste più">
                                            <?php echo esc_url(home_url('?staging=' . $version)); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="button delete-staging-theme" data-version="<?php echo esc_attr($version); ?>" data-nonce="<?php echo wp_create_nonce('delete_staging_theme'); ?>"><?php echo $theme_exists ? 'Elimina' : 'Rimuovi dalla lista'; ?></button>
                                </td>
                                </tr>
                            <?php endforeach; ?>
                    </tbody>
                </table>

                <style>
                    .staging-missing {
                        background-color: #ffe6e6;
                    }

                    .staging-url-missing {
                        color: #999;
                        text-decoration: line-through;
                        cursor: not-allowed;
                    }
                </style>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
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
                                beforeSend: function() {
                                    button.prop('disabled', true).text('Eliminazione...');
                                },
                                success: function(response) {
                                    if (response.success) {
                                        button.closest('tr').fadeOut(400, function() {
                                            $(this).remove();
                                        });
                                    } else {
                                        alert('Errore: ' + (response.data || 'Si è verificato un errore durante l\'elaborazione.'));
                                        button.prop('disabled', false).text('Riprova');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.log('Errore AJAX:', xhr.responseText);
                                    alert('Si è verificato un errore durante l\'eliminazione. Controllare la console per dettagli.');
                                    button.prop('disabled', false).text('Riprova');
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
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('staging-theme-sticky', plugin_dir_url(__FILE__) . 'js/staging-sticky.js', array(), '1.0', true);
});

// Inizializza il plugin
$staging_theme = new Staging_Theme();

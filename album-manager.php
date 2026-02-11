<?php
/**
 * Plugin Name: Album Manager
 * Description: Manage hierarchical photo albums with drag & drop uploads.
 * Version: 0.1.1
 * Author: Example
 * Text Domain: album-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class AM_Plugin {
    public function __construct() {
        add_action( 'init', array( $this, 'register_cpt' ) );
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_post_am_save_album', array( $this, 'save_album' ) );
        add_action( 'admin_post_am_delete_album', array( $this, 'delete_album' ) );
        add_action( 'wp_ajax_am_upload', array( $this, 'handle_upload' ) );
        add_action( 'wp_ajax_am_remove_attachment', array( $this, 'remove_attachment' ) );
        add_action('wp_ajax_am_attach_image', array($this, 'attach_image'));
        register_activation_hook( __FILE__, array( $this, 'flush_rewrite_rules' ) );
    }

    public function register_cpt() {
        $labels = array(
            'name'          => __( 'Albums', 'album-manager' ),
            'singular_name' => __( 'Album', 'album-manager' ),
            'add_new_item'  => __( 'Ajouter un nouvel album', 'album-manager' ),
            'edit_item'     => __( 'Modifier l\'album', 'album-manager' ),
        );
        $args = array(
            'labels'        => $labels,
            'public'        => true,
            'publicly_queryable'  => true,  // Permettre les requêtes publiques
            'show_ui'       => true,
            'show_in_menu'  => false,
            'rewrite'             => array(
                'slug' => 'galerie',  // Notre URL de base
                'with_front' => false,
                'pages' => true,
                'feeds' => true,
            ),
            'capability_type'     => 'post',
            'has_archive'         => false, // Pas besoin d'archive
            'hierarchical'  => true,
            'menu_position'       => null,
            'supports'      => array( 'title' ),
            'show_in_rest'        => true,  // Pour Gutenberg si nécessaire
            'query_var'           => true,
        );
        register_post_type( 'album', $args );

        error_log('CPT Album registered');
        error_log('Rewrite rules: ' . print_r(get_option('rewrite_rules'), true));
    }

    /**
     * Register album_category taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => __('Catégories d\'album', 'album-manager'),
            'singular_name'     => __('Catégorie d\'album', 'album-manager'),
            'search_items'      => __('Rechercher des catégories', 'album-manager'),
            'all_items'         => __('Toutes les catégories', 'album-manager'),
            'edit_item'         => __('Modifier la catégorie', 'album-manager'),
            'update_item'       => __('Mettre à jour la catégorie', 'album-manager'),
            'add_new_item'      => __('Ajouter une nouvelle catégorie', 'album-manager'),
            'new_item_name'     => __('Nom de la nouvelle catégorie', 'album-manager'),
            'menu_name'         => __('Catégories', 'album-manager'),
        );

        $args = array(
            'labels'            => $labels,
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'rewrite'           => array('slug' => 'categorie-album'),
            'capabilities'      => array(
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ),
        );

        register_taxonomy('album_category', array('album'), $args);

        // Insert default terms on first activation
        $this->ensure_default_terms();

        error_log('Album taxonomy registered');
    }

    /**
     * Ensure default category terms exist
     */
    private function ensure_default_terms() {
        $default_terms = array(
            'diaporama' => array(
                'name' => 'Diaporama',
                'slug' => 'diaporama',
                'description' => 'Albums vidéo uniquement (YouTube)'
            ),
            'jardins-membres' => array(
                'name' => 'Jardins membres',
                'slug' => 'jardins-membres',
                'description' => 'Jardins des membres (max 12 photos)'
            ),
            'visites-jardins' => array(
                'name' => 'Visites de jardins',
                'slug' => 'visites-jardins',
                'description' => 'Visites de jardins organisées'
            ),
            'voyages' => array(
                'name' => 'Voyages',
                'slug' => 'voyages',
                'description' => 'Albums de voyages'
            ),
        );

        foreach ($default_terms as $term_slug => $term_data) {
            if (!term_exists($term_slug, 'album_category')) {
                $result = wp_insert_term(
                    $term_data['name'],
                    'album_category',
                    array(
                        'slug' => $term_data['slug'],
                        'description' => $term_data['description']
                    )
                );
                if (!is_wp_error($result)) {
                    error_log("Album category created: {$term_data['name']}");
                }
            }
        }
    }

    public function flush_rewrite_rules() {
        $this->register_cpt();
        $this->register_taxonomy();
        flush_rewrite_rules();
    }

    public function admin_menu() {
        add_submenu_page( 'upload.php', __( 'Albums', 'album-manager' ), __( 'Albums', 'album-manager' ), 'upload_files', 'am-albums', array( $this, 'albums_page' ) );
        add_submenu_page( null, __( 'Edit Album', 'album-manager' ), __( 'Edit Album', 'album-manager' ), 'upload_files', 'am-edit-album', array( $this, 'edit_album_page' ) );
        add_submenu_page( 'upload.php', __( 'Add Album', 'album-manager' ), __( 'Add Album', 'album-manager' ), 'upload_files', 'am-add-album', array( $this, 'edit_album_page' ) );
    }

    public function enqueue_scripts($hook) {
        if (!in_array($hook, array('media_page_am-add-album', 'media_page_am-edit-album'))) {
            return;
        }

        // Charger les scripts nécessaires
        wp_enqueue_media();
        wp_enqueue_script('jquery');
        wp_enqueue_script('wp-util');

        // Enregistrer et charger le script admin
        wp_register_script(
            'am-admin',
            plugin_dir_url(__FILE__) . 'admin.js',
            array('jquery', 'media-upload', 'media-views'),
            '1.2',
            true
        );

        // Localiser le script avec les variables nécessaires
        wp_localize_script('am-admin', 'amVars', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('am_upload'),
            'delNonce' => wp_create_nonce('am_delete')
        ));

        wp_enqueue_script('am-admin');
        wp_enqueue_style('am-admin', plugin_dir_url(__FILE__) . 'admin.css', array(), '1.2');
    }

    public function albums_page() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Albums', 'album-manager' ) . '</h1>';
        echo '<ul class="am-tree">';
        $this->render_tree();
        echo '</ul></div>';
    }

    private function render_tree( $parent = 0 ) {
        $albums = get_posts( array(
            'post_type'      => 'album',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'post_parent'    => $parent,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ) );
        foreach ( $albums as $album ) {
            // Récupérer la catégorie de l'album
            $category_badge = '';
            $terms = wp_get_object_terms($album->ID, 'album_category', array('fields' => 'all'));
            if (!empty($terms) && !is_wp_error($terms)) {
                $term = $terms[0];
                $category_badge = ' <span class="am-category-badge am-category-' . esc_attr($term->slug) . '">' . esc_html($term->name) . '</span>';
            }

            echo '<li><a href="' . esc_url( admin_url( 'upload.php?page=am-edit-album&album_id=' . $album->ID ) ) . '">' . esc_html( get_the_title( $album ) ) . '</a>' . $category_badge;
            $children = get_children( array( 'post_parent' => $album->ID, 'post_type' => 'album' ) );
            if ( $children ) {
                echo '<ul>';
                $this->render_tree( $album->ID );
                echo '</ul>';
            }
            echo '</li>';
        }
    }

    public function edit_album_page() {
        $album_id = isset( $_GET['album_id'] ) ? intval( $_GET['album_id'] ) : 0;
        $album    = $album_id ? get_post( $album_id ) : null;

        echo '<div class="wrap">';
        echo '<h1>' . ( $album ? 'Modifier l\'album' : 'Ajouter un album' ) . '</h1>';

        if ($album) {
            echo '<div class="notice notice-info">';
            echo '<p><strong>URL de l\'album :</strong> <a href="' . get_permalink($album_id) . '" target="_blank">' . get_permalink($album_id) . '</a></p>';
            echo '</div>';
        }

        echo '<form method="post" action="' . admin_url( 'admin-post.php' ) . '" enctype="multipart/form-data">';
        wp_nonce_field( 'am_save_album' );
        echo '<input type="hidden" name="action" value="am_save_album" />';

        if ( $album ) {
            echo '<input type="hidden" name="album_id" value="' . esc_attr( $album->ID ) . '" />';
        }

        echo '<table class="form-table">';
        echo '<tr><th><label for="am_title">Titre</label></th>';
        echo '<td><input type="text" name="am_title" id="am_title" class="regular-text" value="' . ( $album ? esc_attr( $album->post_title ) : '' ) . '" required /></td></tr>';

        // Champ Catégorie
        $current_category = '';
        if ($album) {
            $terms = wp_get_object_terms($album->ID, 'album_category', array('fields' => 'slugs'));
            if (!empty($terms) && !is_wp_error($terms)) {
                $current_category = $terms[0];
            }
        }

        $categories = get_terms(array(
            'taxonomy' => 'album_category',
            'hide_empty' => false,
        ));

        echo '<tr><th><label for="am_category">Catégorie *</label></th>';
        echo '<td><select name="am_category" id="am_category" class="regular-text" required>';
        echo '<option value="">-- Sélectionner une catégorie --</option>';
        if (!empty($categories) && !is_wp_error($categories)) {
            foreach ($categories as $category) {
                $selected = ($current_category === $category->slug) ? 'selected' : '';
                echo '<option value="' . esc_attr($category->slug) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Choisissez la catégorie de l\'album', 'album-manager') . '</p>';
        echo '</td></tr>';

        $date = $album ? get_post_meta( $album->ID, 'album_date', true ) : '';
        $end_date = $album ? get_post_meta( $album->ID, 'album_end_date', true ) : '';

        // Déterminer si on affiche deux dates (voyages + diaporama)
        $show_date_range = in_array($current_category, ['voyages', 'diaporama'], true);
        $single_display = $show_date_range ? 'style="display:none;"' : '';
        $range_display = $show_date_range ? '' : 'style="display:none;"';
        $single_disabled = $show_date_range ? 'disabled' : '';
        $range_disabled = $show_date_range ? '' : 'disabled';

        echo '<tr id="am-single-date-field" ' . $single_display . '>';
        echo '<th><label for="am_date_single">Date</label></th>';
        echo '<td><input type="date" name="am_date" id="am_date_single" value="' . esc_attr( $date ) . '" ' . $single_disabled . ' /></td></tr>';

        echo '<tr id="am-date-start-field" ' . $range_display . '>';
        echo '<th><label for="am_date_start">Date aller</label></th>';
        echo '<td><input type="date" name="am_date" id="am_date_start" value="' . esc_attr( $date ) . '" ' . $range_disabled . ' /></td></tr>';

        echo '<tr id="am-date-end-field" ' . $range_display . '>';
        echo '<th><label for="am_end_date">Date retour</label></th>';
        echo '<td><input type="date" name="am_end_date" id="am_end_date" value="' . esc_attr( $end_date ) . '" ' . $range_disabled . ' />';
        echo '<p class="description">Laissez vide si l\'événement dure un seul jour</p>';
        echo '</td></tr>';

        echo '<tr><th><label for="am_parent">Album parent</label></th><td>';
        wp_dropdown_pages( array(
            'post_type' => 'album',
            'selected' => $album ? $album->post_parent : 0,
            'name' => 'am_parent',
            'show_option_none' => '(Aucun)',
            'sort_column' => 'menu_order, post_title',
            'exclude' => $album_id ? array( $album_id ) : array(),
        ) );
        echo '</td></tr>';

        // Champ URL YouTube (uniquement pour Diaporama)
        $youtube_url = $album ? get_post_meta( $album->ID, 'youtube_url', true ) : '';
        $youtube_required = ($current_category === 'diaporama') ? 'required' : '';
        $youtube_display = ($current_category === 'diaporama') ? '' : 'style="display:none;"';

        echo '<tr id="am-youtube-field" ' . $youtube_display . '>';
        echo '<th><label for="am_youtube_url">URL YouTube *</label></th>';
        echo '<td><input type="url" name="am_youtube_url" id="am_youtube_url" class="regular-text" value="' . esc_attr( $youtube_url ) . '" placeholder="https://www.youtube.com/watch?v=..." ' . $youtube_required . ' />';
        echo '<p class="description">URL YouTube obligatoire pour les albums Diaporama</p>';

        // Aperçu de la vidéo si elle existe
        if ( $youtube_url ) {
            $video_id = $this->extract_youtube_id( $youtube_url );
            if ( $video_id ) {
                echo '<div id="am-youtube-preview" style="margin-top: 10px;"><strong>Aperçu :</strong><br>';
                echo '<iframe width="400" height="225" src="https://www.youtube.com/embed/' . esc_attr( $video_id ) . '" frameborder="0" allowfullscreen></iframe></div>';
            }
        }
        echo '</td></tr>';

        echo '</table>';

        if ( $album ) {
            // Compter les images existantes
            $attachments = get_children( array(
                'post_parent' => $album->ID,
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'orderby' => 'menu_order',
                'order' => 'ASC',
            ) );
            $photo_count = count($attachments);

            echo '<h2>Images de l\'album</h2>';

            // Si c'est un diaporama, afficher un avertissement au lieu du dropzone
            if ($current_category === 'diaporama') {
                echo '<div class="notice notice-warning" id="am-diaporama-notice">';
                echo '<p><strong>Les albums Diaporama ne peuvent contenir que des vidéos YouTube.</strong></p>';
                echo '<p>Veuillez ajouter une URL YouTube ci-dessus. Les images ne peuvent pas être uploadées pour cette catégorie.</p>';
                echo '</div>';
            } else {
                // Afficher le compteur pour jardins-membres
                if ($current_category === 'jardins-membres') {
                    echo '<div class="notice notice-info" id="am-photo-counter">';
                    echo '<p><strong>Photos : <span id="am-photo-count">' . $photo_count . '</span>/12</strong></p>';
                    if ($photo_count >= 12) {
                        echo '<p>Limite de 12 photos atteinte. Supprimez des photos pour en ajouter de nouvelles.</p>';
                    }
                    echo '</div>';
                }

                echo '<div id="am-dropzone" class="am-dropzone">';
                echo '<button type="button" id="am-upload-browse" class="button button-primary">Sélectionner des images</button>';
                echo '<p class="description">Glissez-déposez des images ici ou cliquez pour sélectionner</p>';
                echo '</div>';

                echo '<ul id="am-gallery" class="am-gallery">';
                foreach ( $attachments as $attachment ) {
                    $thumb_url = wp_get_attachment_thumb_url( $attachment->ID );
                    $full_url  = wp_get_attachment_url( $attachment->ID );
                    echo '<li data-id="' . esc_attr( $attachment->ID ) . '">';
                    echo '<a href="' . esc_url( $full_url ) . '" target="_blank">';
                    echo '<img src="' . esc_url( $thumb_url ) . '" alt="" />';
                    echo '</a>';
                    echo '<span class="am-remove" title="Supprimer">×</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
        }

        submit_button( $album ? 'Mettre à jour' : 'Créer' );
        
        // Ajout du bouton de suppression si c'est un album existant
        if ($album) {
            echo ' <a href="' . wp_nonce_url(admin_url('admin-post.php?action=am_delete_album&album_id=' . $album_id), 'am_delete_album_' . $album_id) . '" class="button button-link-delete" onclick="return confirm(\'Êtes-vous sûr de vouloir supprimer définitivement cet album ? Cette action est irréversible.\')">Supprimer l\'album</a>';
        }
        
        echo '</form></div>';
    }

    public function save_album() {
        check_admin_referer( 'am_save_album' );
        $title  = sanitize_text_field( $_POST['am_title'] );
        $date   = sanitize_text_field( $_POST['am_date'] );
        $end_date = isset( $_POST['am_end_date'] ) ? sanitize_text_field( $_POST['am_end_date'] ) : '';
        $parent = intval( $_POST['am_parent'] );
        $youtube_url = isset( $_POST['am_youtube_url'] ) ? esc_url_raw( $_POST['am_youtube_url'] ) : '';
        $category = isset( $_POST['am_category'] ) ? sanitize_text_field( $_POST['am_category'] ) : '';
        $album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

        // Validation: Diaporama doit avoir une URL YouTube
        if ($category === 'diaporama' && empty($youtube_url)) {
            wp_die( __('Les albums Diaporama doivent obligatoirement avoir une URL YouTube.', 'album-manager'), __('Erreur de validation', 'album-manager'), array('back_link' => true) );
        }

        $data = array(
            'post_title'  => $title,
            'post_type'   => 'album',
            'post_status' => 'publish',
            'post_parent' => $parent,
        );
        if ( $album_id ) {
            $data['ID'] = $album_id;
            wp_update_post( $data );
        } else {
            $album_id = wp_insert_post( $data );
        }
        if ( $date ) {
            update_post_meta( $album_id, 'album_date', $date );
        } else {
            delete_post_meta( $album_id, 'album_date' );
        }

        // Sauvegarde de la date de fin (pour voyages et visites)
        if ( $end_date ) {
            update_post_meta( $album_id, 'album_end_date', $end_date );
        } else {
            delete_post_meta( $album_id, 'album_end_date' );
        }

        // Sauvegarde de l'URL YouTube (uniquement pour Diaporama)
        if ( $category === 'diaporama' && $youtube_url ) {
            update_post_meta( $album_id, 'youtube_url', $youtube_url );
        } else {
            // Supprimer l'URL YouTube si la catégorie n'est pas Diaporama
            delete_post_meta( $album_id, 'youtube_url' );
        }

        // Sauvegarde de la catégorie
        if ( $category ) {
            $result = wp_set_object_terms( $album_id, $category, 'album_category' );
            if ( is_wp_error( $result ) ) {
                error_log( 'Erreur lors de la sauvegarde de la catégorie : ' . $result->get_error_message() );
            } else {
                error_log( 'Catégorie sauvegardée pour l\'album ' . $album_id . ': ' . $category );
            }
        }

        wp_redirect( admin_url( 'upload.php?page=am-edit-album&album_id=' . $album_id ) );
        exit;
    }

    public function handle_upload() {
        check_ajax_referer( 'am_upload', 'nonce' );

        $album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

        if ( ! $album_id ) {
            wp_send_json_error( array( 'message' => 'Aucun album spécifié' ) );
            return;
        }

        // Validation basée sur la catégorie
        $terms = wp_get_object_terms( $album_id, 'album_category', array('fields' => 'slugs') );
        if ( !is_wp_error($terms) && !empty($terms) ) {
            $category = $terms[0];

            // Bloquer les images pour les albums Diaporama
            if ( $category === 'diaporama' ) {
                wp_send_json_error( array( 'message' => 'Les albums Diaporama ne peuvent contenir que des vidéos YouTube. Les images ne sont pas autorisées.' ) );
                return;
            }

            // Vérifier la limite pour Jardins membres
            if ( $category === 'jardins-membres' ) {
                $existing_images = get_children( array(
                    'post_parent' => $album_id,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image',
                ) );
                if ( count($existing_images) >= 12 ) {
                    wp_send_json_error( array( 'message' => 'Limite de 12 photos atteinte pour les albums Jardins membres. Supprimez des photos pour en ajouter de nouvelles.' ) );
                    return;
                }
            }
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $uploadedfile = $_FILES['async-upload'];
        $upload_overrides = array( 'test_form' => false );

        $movefile = wp_handle_upload( $uploadedfile, $upload_overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $filetype = wp_check_filetype( basename( $movefile['file'] ), null );

            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $movefile['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $album_id,
            );

            $attach_id = wp_insert_attachment( $attachment, $movefile['file'], $album_id );

            if ( ! is_wp_error( $attach_id ) ) {
                $attach_data = wp_generate_attachment_metadata( $attach_id, $movefile['file'] );
                wp_update_attachment_metadata( $attach_id, $attach_data );

                $thumb_url = wp_get_attachment_thumb_url( $attach_id );

                wp_send_json_success( array(
                    'id'  => $attach_id,
                    'url' => $thumb_url,
                ) );
                return;
            }
        }

        wp_send_json_error( array(
            'message' => isset( $movefile['error'] ) ? $movefile['error'] : 'Erreur inconnue',
        ) );
    }

    public function remove_attachment() {
        check_ajax_referer( 'am_delete', 'nonce' );

        $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( 'ID de pièce jointe manquant' );
            return;
        }

        if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
            wp_send_json_error( 'Permission refusée' );
            return;
        }

        $result = wp_update_post( array(
            'ID'         => $attachment_id,
            'post_parent' => 0,
        ), true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success();
        }
    }

    public function attach_image() {
        check_ajax_referer('am_upload', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Permission refusée');
        }

        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        $album_id = isset($_POST['album_id']) ? intval($_POST['album_id']) : 0;

        if (!$attachment_id || !$album_id) {
            wp_send_json_error('Paramètres manquants');
        }

        // Validation basée sur la catégorie
        $terms = wp_get_object_terms( $album_id, 'album_category', array('fields' => 'slugs') );
        if ( !is_wp_error($terms) && !empty($terms) ) {
            $category = $terms[0];

            // Bloquer les images pour les albums Diaporama
            if ( $category === 'diaporama' ) {
                wp_send_json_error( 'Les albums Diaporama ne peuvent contenir que des vidéos YouTube. Les images ne sont pas autorisées.' );
                return;
            }

            // Vérifier la limite pour Jardins membres
            if ( $category === 'jardins-membres' ) {
                $existing_images = get_children( array(
                    'post_parent' => $album_id,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image',
                ) );
                if ( count($existing_images) >= 12 ) {
                    wp_send_json_error( 'Limite de 12 photos atteinte pour les albums Jardins membres. Supprimez des photos pour en ajouter de nouvelles.' );
                    return;
                }
            }
        }

        $result = wp_update_post(array(
            'ID' => $attachment_id,
            'post_parent' => $album_id
        ), true);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $thumb_url = wp_get_attachment_thumb_url($attachment_id);
        wp_send_json_success(array(
            'id' => $attachment_id,
            'url' => $thumb_url
        ));
    }

    /**
     * Supprime un album et ses images associées
     */
    public function delete_album() {
        if (!isset($_GET['album_id']) || !isset($_GET['_wpnonce'])) {
            wp_die('Paramètres manquants');
        }

        $album_id = intval($_GET['album_id']);
        
        // Vérification du nonce
        if (!wp_verify_nonce($_GET['_wpnonce'], 'am_delete_album_' . $album_id)) {
            wp_die('Nonce de sécurité invalide');
        }

        // Vérification des droits
        if (!current_user_can('delete_posts', $album_id)) {
            wp_die('Vous n\'avez pas les droits nécessaires pour effectuer cette action');
        }

        // Récupérer toutes les images attachées à l'album
        $attachments = get_children(array(
            'post_parent' => $album_id,
            'post_type' => 'attachment'
        ));

        // Supprimer toutes les images attachées
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                wp_delete_attachment($attachment->ID, true);
            }
        }

        // Supprimer l'album
        $result = wp_delete_post($album_id, true);

        if ($result === false) {
            wp_die('Erreur lors de la suppression de l\'album');
        }

        // Rediriger vers la liste des albums avec un message de succès
        wp_redirect(admin_url('upload.php?page=am-albums&deleted=1'));
        exit;
    }

    /**
     * Extract YouTube video ID from URL
     */
    private function extract_youtube_id( $url ) {
        preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/', $url, $matches);
        return isset( $matches[1] ) ? $matches[1] : false;
    }
}

new AM_Plugin();

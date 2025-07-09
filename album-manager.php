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
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_post_am_save_album', array( $this, 'save_album' ) );
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

    public function flush_rewrite_rules() {
        $this->register_cpt();
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
            echo '<li><a href="' . esc_url( admin_url( 'upload.php?page=am-edit-album&album_id=' . $album->ID ) ) . '">' . esc_html( get_the_title( $album ) ) . '</a>';
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

        echo '<form method="post" action="' . admin_url( 'admin-post.php' ) . '" enctype="multipart/form-data">';
        wp_nonce_field( 'am_save_album' );
        echo '<input type="hidden" name="action" value="am_save_album" />';

        if ( $album ) {
            echo '<input type="hidden" name="album_id" value="' . esc_attr( $album->ID ) . '" />';
        }

        echo '<table class="form-table">';
        echo '<tr><th><label for="am_title">Titre</label></th>';
        echo '<td><input type="text" name="am_title" id="am_title" class="regular-text" value="' . ( $album ? esc_attr( $album->post_title ) : '' ) . '" required /></td></tr>';

        $date = $album ? get_post_meta( $album->ID, 'album_date', true ) : '';
        echo '<tr><th><label for="am_date">Date</label></th>';
        echo '<td><input type="date" name="am_date" id="am_date" value="' . esc_attr( $date ) . '" /></td></tr>';

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
        echo '</table>';

        if ( $album ) {
            echo '<h2>Images de l\'album</h2>';
            echo '<div id="am-dropzone" class="am-dropzone">';
            echo '<button type="button" id="am-upload-browse" class="button button-primary">Sélectionner des images</button>';
            echo '<p class="description">Glissez-déposez des images ici ou cliquez pour sélectionner</p>';
            echo '</div>';

            echo '<ul id="am-gallery" class="am-gallery">';
            $attachments = get_children( array(
                'post_parent' => $album->ID,
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'orderby' => 'menu_order',
                'order' => 'ASC',
            ) );

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

        submit_button( $album ? 'Mettre à jour' : 'Créer' );
        echo '</form></div>';
    }

    public function save_album() {
        check_admin_referer( 'am_save_album' );
        $title  = sanitize_text_field( $_POST['am_title'] );
        $date   = sanitize_text_field( $_POST['am_date'] );
        $parent = intval( $_POST['am_parent'] );
        $album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

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
}

new AM_Plugin();
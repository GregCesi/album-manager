jQuery(function($) {
    // Fonction pour afficher les champs de date selon la catégorie
    function updateDateFields(category) {
        if (category === 'voyages' || category === 'diaporama') {
            $('#am-single-date-field').hide();
            $('#am-date-start-field').show();
            $('#am-date-end-field').show();
            $('#am_date_single').prop('disabled', true);
            $('#am_date_start, #am_end_date').prop('disabled', false);
        } else {
            $('#am-single-date-field').show();
            $('#am-date-start-field').hide();
            $('#am-date-end-field').hide();
            $('#am_date_single').prop('disabled', false);
            $('#am_date_start, #am_end_date').prop('disabled', true);
        }
    }

    // Fonction pour mettre à jour l'UI selon la catégorie
    function updateUIForCategory() {
        var category = $('#am_category').val();

        updateDateFields(category);

        if (category === 'diaporama') {
            // Masquer le dropzone et la galerie pour Diaporama
            $('#am-dropzone').hide();
            $('#am-gallery').hide();
            $('#am-diaporama-notice').show();

            // Afficher le champ YouTube (obligatoire pour Diaporama)
            $('#am-youtube-field').show();
            $('#am_youtube_url').attr('required', 'required');
        } else {
            // Afficher le dropzone et la galerie
            $('#am-dropzone').show();
            $('#am-gallery').show();
            $('#am-diaporama-notice').hide();

            // Masquer le champ YouTube (uniquement pour Diaporama)
            $('#am-youtube-field').hide();
            $('#am_youtube_url').removeAttr('required');

            // Afficher/masquer le compteur pour jardins-membres
            if (category === 'jardins-membres') {
                $('#am-photo-counter').show();
                checkPhotoLimit();
            } else {
                $('#am-photo-counter').hide();
            }
        }
    }

    // Fonction pour vérifier la limite de photos
    function checkPhotoLimit() {
        var category = $('#am_category').val();
        if (category !== 'jardins-membres') return;

        var photoCount = $('#am-gallery li').length;
        $('#am-photo-count').text(photoCount);

        if (photoCount >= 12) {
            $('#am-upload-browse').prop('disabled', true).addClass('disabled');
        } else {
            $('#am-upload-browse').prop('disabled', false).removeClass('disabled');
        }
    }

    // Événement de changement de catégorie
    $('#am_category').on('change', updateUIForCategory);

    // Appeler updateUIForCategory au chargement de la page
    updateUIForCategory();

    // Vérifier si l'album existe avant d'initialiser l'uploader
    if ($('#am-upload-browse').length === 0) return;

    // Initialisation de l'uploader
    var uploader = new wp.media({
        title: 'Ajouter des images',
        button: {
            text: 'Utiliser ces images'
        },
        multiple: true
    });

    // Gestionnaire du bouton d'ajout
    $('#am-upload-browse').on('click', function(e) {
        e.preventDefault();
        
        uploader.open();
    });

    // Quand des fichiers sont sélectionnés
    uploader.on('select', function() {
        var attachments = uploader.state().get('selection').toJSON();
        var albumId = $("input[name='album_id']").val();

        $.each(attachments, function(index, attachment) {
            $.post(amVars.ajaxUrl, {
                action: 'am_attach_image',
                attachment_id: attachment.id,
                album_id: albumId,
                nonce: amVars.nonce
            }).done(function(response) {
                if (response.success) {
                    var li = $('<li data-id="' + response.data.id + '">' +
                        '<img src="' + response.data.url + '" alt="" />' +
                        '<span class="am-remove" title="Supprimer">×</span>' +
                        '</li>');
                    $('#am-gallery').append(li);
                    checkPhotoLimit();
                } else if (response.data && response.data.message) {
                    alert(response.data.message);
                }
            }).fail(function(error) {
                console.error('Erreur:', error);
                if (error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
                    alert(error.responseJSON.data.message);
                } else {
                    alert('Erreur lors de l\'ajout de l\'image');
                }
            });
        });
    });

    // Suppression d'une image
    $(document).on('click', '.am-remove', function(e) {
        e.preventDefault();
        if (!confirm('Supprimer cette image ?')) return;

        var $li = $(this).closest('li');
        var attachmentId = $li.data('id');

        $.post(amVars.ajaxUrl, {
            action: 'am_remove_attachment',
            attachment_id: attachmentId,
            nonce: amVars.delNonce
        }).done(function() {
            $li.fadeOut(300, function() {
                $(this).remove();
                checkPhotoLimit();
            });
        }).fail(function() {
            alert('Erreur lors de la suppression');
        });
    });
});

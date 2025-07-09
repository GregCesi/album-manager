jQuery(function($) {
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
                }
            }).fail(function(error) {
                console.error('Erreur:', error);
                alert('Erreur lors de l\'ajout de l\'image');
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
            $li.fadeOut(300, function() { $(this).remove(); });
        }).fail(function() {
            alert('Erreur lors de la suppression');
        });
    });
});
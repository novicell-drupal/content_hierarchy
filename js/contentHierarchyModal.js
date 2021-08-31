Drupal.behaviors.contentHierarchyModal = {
  attach: function (context, settings) {
    jQuery('#edit-langcode-wrapper select', context).change(function() {
      let langcode = jQuery('#edit-langcode-wrapper select').val();
      let contentId = jQuery('.content-hierarchy-modal__widget').data('content-id');
      let endpoint = '/admin/content/hierarchy/ajax/modal/update/' + langcode + '/' + contentId;
      Drupal.ajax({url: endpoint}).execute();
    });
  }
};

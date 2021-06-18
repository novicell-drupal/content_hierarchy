Drupal.behaviors.contentHierarchySelect = {
  attach: function (context, settings) {
    let langcode = jQuery('#edit-langcode-wrapper select').val();
    let current = JSON.parse(jQuery('.content-hierarchy-current').val());
    if (langcode === undefined) {
      langcode = current.langcode;
    }
    jQuery.get( "/admin/content/hierarchy/ajax/select/" + langcode + '/' + current.langcode + '/' + current.placement, function( data ) {
      jQuery('.content-hierarchy-select', context).html(data);
    });
    jQuery('#edit-langcode-wrapper select', context).change(function() {
      jQuery('.field--widget-content-hierarchy-select select').html('');
      let langcode = jQuery('#edit-langcode-wrapper select').val();
      jQuery.get( "/admin/content/hierarchy/ajax/select/" + langcode + '/' + current.langcode + '/' + current.placement, function( data ) {
        jQuery('.content-hierarchy-select').html(data);
      });
    });
  }
};

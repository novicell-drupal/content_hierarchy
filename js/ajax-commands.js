'use strict';
(function($, Drupal) {
  Drupal.AjaxCommands.prototype.toggleCommand = function (ajax, response, status) {
    if (parseInt(response.id) > 0) {
      var id = parseInt(response.id);
      var $contentOverview = jQuery('.content-overview');
      if ($contentOverview.find('li#' + id + ' .content-hierarchy__children ul li').length) {
        $contentOverview.find('li#' + id + ' .content-hierarchy__children ul li').remove();
        if ($contentOverview.find('li#' + id + ' .content-hierarchy__expand').first()) {
          $contentOverview.find('li#' + id + ' .content-hierarchy__expand a').first().html('( + )');
        }
      } else {
        Drupal.AjaxCommands.prototype.insert(ajax, {
          'selector': 'li#' + id + ' div.content-hierarchy__children',
          'method': 'html',
          'data': response.data
        });
        if ($contentOverview.find('li#' + id + ' .content-hierarchy__expand').first()) {
          $contentOverview.find('li#' + id + ' .content-hierarchy__expand a').first().html('( - )');
        }
      }
    }
  };
})(jQuery, Drupal);

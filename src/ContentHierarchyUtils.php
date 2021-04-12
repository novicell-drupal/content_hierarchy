<?php

namespace Drupal\content_hierarchy;

use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use UnexpectedValueException;

class ContentHierarchyUtils {

//  public static function loadLocalizedSiteSettings() {
//    return NULL;
//    $current_language = \Drupal::languageManager()->getCurrentLanguage();
//    $default_language = \Drupal::languageManager()->getDefaultLanguage();
//
//    $languages_codes = [
//      $current_language->getId(),
//      $default_language->getId(),
//    ];
//    $query = \Drupal::database()
//      ->select('site_setting_entity', 's')
//      ->fields('s', ['langcode', 'id']);
//    $query->condition('type', 'localized_settings')
//      ->condition('langcode', $languages_codes, 'IN');
//    $site_setting_id = $query->execute()->fetchAllKeyed();
//
//    if (!empty($site_setting_id) && isset(
//        $site_setting_id[$current_language->getId()]
//      )) {
//      return SiteSettingEntity::load(
//        $site_setting_id[$current_language->getId()]
//      );
//    }
//
//    return NULL;
//  }

  /**
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\EntityInterface|null
   */
  public static function getFrontpageEntity() {
    $frontpage = NULL;
    $config = \Drupal::config('system.site');

    // Get frontpage from config
    $front_uri = $config->get('page.front');
    // Assume it's internal and get route params.
    $url = Url::fromUri("internal:" . $front_uri);
    if ($url->isExternal()) {
      return NULL;
    }
    try {
      $params = $url->getRouteParameters();
    } catch (UnexpectedValueException $exception) {
      return NULL;
    }
    $frontpage_type = key($params);
    try {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $frontpage */
      $frontpage = \Drupal::entityTypeManager()
        ->getStorage($frontpage_type)
        ->load($params[$frontpage_type]);
    } catch (\Throwable $e) {
      /** @var \Psr\Log\LoggerInterface $logger */
      \Drupal::logger('content_hierarchy')
        ->error(
          'An error occured while trying to get the frontpage entity: %s',
          ['%s' => $e->getMessage()]
        );
    }

    // Get translated version, if it exists.
    $current_language = \Drupal::languageManager()->getCurrentLanguage()->getId(
    );
    if ($frontpage && $frontpage->hasTranslation($current_language)) {
      $frontpage = $frontpage->getTranslation($current_language);
    }

    return $frontpage;
  }

  /**
   * @param string $type
   *
   * @return string
   */
  public static function getNodeType($type) {
    static $labels = [];
    if (empty($labels[$type])) {
      $labels[$type] = NodeType::load($type)->label();
    }
    return $labels[$type];
  }

  /**
   * @param int $uid
   *
   * @return \Drupal\Core\Link
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public static function getAuthorLink($uid) {
    static $links = [];
    if (empty($links[$uid])) {
      $links[$uid] = User::load($uid)->toLink();
    }
    return $links[$uid];
  }
}

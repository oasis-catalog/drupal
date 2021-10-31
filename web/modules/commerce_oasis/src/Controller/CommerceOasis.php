<?php
/**
 * @file
 * Contains \Drupal\commerce_oasis\Controller\Oasis.
 */

namespace Drupal\commerce_oasis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Provides route for our commerce module.
 */
class CommerceOasis extends ControllerBase {

  /**
   * Получение термина производителя, создание при отсутсвии
   *
   * @param $vid
   * @param $name
   *
   * @return \Drupal\Core\Entity\EntityBase|\Drupal\Core\Entity\EntityInterface|\Drupal\taxonomy\Entity\Term
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function getBrand($vid, $name) {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vid);

    foreach ($terms as $term) {
      if ($term->name === $name) {
        return $term->tid;
      }
    }

    $term = Term::create([
      // Required fields
      'vid' => CommerceOasis::getVocabulary($vid),
      'name' => $name,
      // Optional fields
      'status' => 1,
      'description' => [
        'value' => '',
        'format' => 'full_html',
      ],
      'changed' => time(),
    ]);
    $term->save();

    return $term->id();
  }

  public static function getCategories($vid, $name) {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vid);

    foreach ($terms as $term) {
      if ($term->name === $name) {
        return $term->tid;
      }
    }

    $term = Term::create([
      // Required fields
      'vid' => CommerceOasis::getVocabulary($vid),
      'name' => $name,
      // Optional fields
      'status' => 1,
      'description' => [
        'value' => '',
        'format' => 'full_html',
      ],
      'changed' => time(),
    ]);
    $term->save();

    return $term->id();
  }

  /**
   * Получение таксономии производителя, создание при отсутсвии
   *
   * @param $vid
   * @param string $name
   *
   * @return string
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function getVocabulary($vid, string $name = ''): string {
    $vocabularies = Vocabulary::loadMultiple();
    if (!isset($vocabularies[$vid])) {
      $vocabulary = Vocabulary::create([
        'vid' => $vid,
        'description' => '',
        'name' => $name ? $name : $vid,
      ]);
      $vocabulary->save();
    }

    return $vid;
  }

  /**
   * @return array
   */
  public static function getOasisCategories(): array {
    $result = [];
    $categories = CommerceOasis::curlQuery('v4/', 'categories', ['fields' => 'id,parent_id,root,level,slug,name,path']);

    foreach ($categories as $category) {
      if ($category->level === 1) {
        $result[$category->id] = $category->name;
      }
    }

    return $result;
  }

  /**
   * @return array
   */
  public static function getOasisCurrency(): array {
    $arrCurr = [];
    $currencies = CommerceOasis::curlQuery('v4/', 'currencies');
    $ruble = [];

    if ($currencies) {
      foreach ($currencies as $currency) {
        if ($currency->code === 'rub') {
          $ruble[$currency->code] = $currency->full_name;
        }
        else {
          $arrCurr[$currency->code] = $currency->full_name;
        }
      }
    }

    return $ruble + $arrCurr;
  }

  /**
   * @param       $version
   * @param       $type
   * @param array $args
   *
   * @return false|mixed
   *
   * @since 1.0
   */
  public static function curlQuery($version, $type, array $args = []) {
    $config = \Drupal::config('commerce_oasis.settings');
    $args_pref = [
      'key' => $config->get('oasis_api_key'),
      'format' => 'json',
    ];
    $args = array_merge($args_pref, $args);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.oasiscatalog.com/' . $version . $type . '?' . http_build_query($args));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $result = json_decode(curl_exec($ch));
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $http_code === 200 ? $result : FALSE;
  }

}

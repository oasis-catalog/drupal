<?php
/**
 * @file
 *
 * Contains \Drupal\commerce_oasis\Controller\CommerceOasis.
 */

namespace Drupal\commerce_oasis\Controller;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductAttribute;
use Drupal\commerce_product\Entity\ProductAttributeValue;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Provides route for our commerce module.
 */
class CommerceOasis extends ControllerBase {

  /**
   * The config module Oasis.
   */
  private $config = NULL;

  /**
   * Categories oasis
   *
   * @var null
   */
  private $categories = NULL;

  /**
   * Products oasis
   *
   * @var null
   */
  private $products = NULL;

  /**
   * Default Store Id
   *
   * @var integer
   */
  private $defaultStore;

  /**
   * The data calculation price module Oasis.
   *
   * @var array
   */
  private $calculation = [];

  /**
   * Constructs.
   */
  public function __construct() {
    $this->config = $this->config('commerce_oasis.settings');

    $store = \Drupal::entityTypeManager()
      ->getStorage('commerce_store')
      ->loadByProperties(['is_default' => 1]);

    $this->defaultStore = [array_key_first($store)];
  }

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#theme' => 'oasis_settings',
    ];

    return $build;
  }

  /**
   * @param bool $upStock
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public function doExecuteImport(bool $upStock)
  {
    set_time_limit(0);
    ini_set('memory_limit', '3G');

    try {
      \Drupal::logger('commerce_oasis')->notice('Start process');

      $start_time = microtime(TRUE);

      if ($upStock) {
        $stock = CommerceOasis::getOasisStock();
        if ($stock) {
          $this->upStock($stock);
        }
      } else {
        $args = [];
        $limit = (int)$this->config->get('oasis_limit');
        $step = (int)$this->config->get('oasis_step');
        $this->calculation = [
          'factor'   => (float)$this->config->get('oasis_factor'),
          'increase' => (float)$this->config->get('oasis_increase'),
          'dealer'   => $this->config->get('oasis_dealer'),
        ];

        if ($limit > 0) {
          $args['limit'] = $limit;
          $args['offset'] = $step * $limit;
        }

        $this->categories = CommerceOasis::getOasisCategories();
        $this->products = $this->getOasisProducts($args);

        foreach ($this->products as $product) {
          $this->import($product);
        }

        if (!empty($limit)) {
          if ($this->products) {
            $nextStep = ++$step;
          } else {
            $nextStep = 0;
          }

          \Drupal::service('config.factory')->getEditable('commerce_oasis.settings')->set('oasis_step', $nextStep)->save();
        }
      }
      $end_time = microtime(TRUE);

      \Drupal::logger('commerce_oasis')
        ->notice('End process. ' . 'Время обработки: ' . $this->secToHis($end_time - $start_time));
    } catch (Exception $e) {
    }
  }

  /**
   * Import products
   *
   * @param $product
   * @return int|mixed|string|null
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public function import($product) {
    if (!is_null($product->parent_size_id)) {
      $result = $this->checkProduct($product, 'clothing');
    } elseif (!is_null($product->parent_color_id)) {
      $result = $this->checkProduct($product, 'color');
    } else {
      $result = $this->checkProduct($product, 'other');
    }

    return $result ? $result->id() : '';
  }

  /**
   * Up products quantity
   *
   * @param $stock
   */
  public function upStock($stock) {
    foreach ($stock as $item) {
      $fidoQuery = \Drupal::database()
        ->select('commerce_product_variation__field_id_oasis', 'fido');
      $fidoQuery->addField('fido', 'entity_id');
      $fidoQuery->condition('fido.field_id_oasis_value', $item->id);
      $entity_id = $fidoQuery->execute()->fetchField();

      if ($entity_id) {
        if ($item->stock === 0) {
          $queryStock = \Drupal::database()
            ->select('commerce_product_variation__field_stock', 'fido');
          $queryStock->addField('fido', 'field_stock_value');
          $queryStock->condition('fido.entity_id', $entity_id);
          $variationStock = $queryStock->execute()->fetchField();

          if ((int)$variationStock !== 99999) {
            $fsQuery = \Drupal::database()
              ->update('commerce_product_variation__field_stock');
            $fsQuery->fields([
              'field_stock_value' => $item->stock,
            ]);
            $fsQuery->condition('entity_id', $entity_id);
            $fsQuery->execute();
          }
        } else {
          $fsQuery = \Drupal::database()
            ->update('commerce_product_variation__field_stock');
          $fsQuery->fields([
            'field_stock_value' => $item->stock,
          ]);
          $fsQuery->condition('entity_id', $entity_id);
          $fsQuery->execute();
        }
      }
    }
    unset($item);
  }

  /**
   * @param $product
   * @param string $type
   *
   * @return Product|EntityBase|EntityInterface|false|mixed
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public function checkProduct($product, string $type = 'other') {
    $variation = \Drupal::entityTypeManager()
      ->getStorage('commerce_product_variation')
      ->loadBySku($product->article);
    $cProduct = \Drupal::entityTypeManager()
      ->getStorage('commerce_product')
      ->loadByProperties([
        'title' => $product->name,
        'field_group_id_oasis' => $product->group_id,
      ]);
    $cProduct = reset($cProduct);

    if (is_null($variation)) {
      $colorRadioId = NULL;
      $variation = $this->addVariation($product, $cProduct, $type, $colorRadioId);
      if ($cProduct) {
        if ($type === 'color') {
          $cVariationInProduct = \Drupal::entityTypeManager()
            ->getStorage('commerce_product_variation')
            ->getQuery()
            ->condition('product_id', $cProduct->id())
            ->condition('attribute_color_radio', $colorRadioId)
            ->execute();
          if ($cVariationInProduct) {
            $cProduct = $this->addProduct($product, $variation, $type);
          } else {
            $this->editProduct($variation, $cProduct);
          }
        } else {
          $this->editProduct($variation, $cProduct);
        }
      } else {
        $cProduct = $this->addProduct($product, $variation, $type);
      }
    } else {
      if (is_null($variation->getProduct())) {
        $variation->delete();

      } else {
        $this->editVariation($variation, $product, $type);
      }
    }

    if ($cProduct) {
      $hasEnableVariation = \Drupal::entityTypeManager()
        ->getStorage('commerce_product_variation')
        ->getQuery()
        ->condition('product_id', $cProduct->id())
        ->condition('status', TRUE)
        ->execute();

      if (!$hasEnableVariation) {
        $cProduct->set('status', FALSE)->save();
      }
    }

    return $cProduct;
  }

  /**
   * @param $product
   * @param $cProduct
   * @param $type
   * @param null $colorRadioId
   *
   * @return ProductVariation|EntityBase|EntityInterface
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public function addVariation($product, $cProduct, $type, &$colorRadioId = NULL)
  {
    $attr = [
      'type'           => $type,
      'sku'            => $product->article,
      'price'          => new Price($this->getCalculationPrice($product), 'RUB'),
      'field_body'     => $product->description,
      'field_id_oasis' => [$product->id],
      'status'         => $this->getStatusAndStock($product)['status'],
      'field_stock'    => $this->getStatusAndStock($product)['field_stock'],
    ];

    $attribute_name = 'attribute_color';

    if ($type === 'clothing') {
      $attr['attribute_size'] = CommerceOasis::getAttribute($product->size, 'size');
      foreach ($product->attributes as $attribute) {
        if (isset($attribute->id) && $attribute->id === 1000000001) {
          $attributeColor = explode('/', str_replace(',', '/', $attribute->value));
          foreach ($attributeColor as $attributeColorItem) {
            $needed = CommerceOasis::getHexColor(trim($attributeColorItem));
            if ($needed) {
              $color = [
                'name'  => trim($attributeColorItem),
                'value' => $needed,
              ];
              break;
            }
          }
          unset($attributeColorItem, $needed);
        }
      }
      unset($attribute);

      if (isset($color)) {
        $attr[$attribute_name] = $this->getAttributeColor($color, $type);
      } else {
        foreach ($product->colors as $colorItem) {
          $needed = CommerceOasis::parentColor($colorItem->parent_id);
          if ($needed) {
            $color = [
              'name'  => trim($colorItem->name),
              'value' => CommerceOasis::parentColor($colorItem->parent_id),
            ];
            $attr[$attribute_name] = $this->getAttributeColor($color, $type);
            break;
          }
        }
      }
    } elseif ($type === 'color') {
      $attribute_name = 'attribute_color_radio';
      foreach ($product->attributes as $attribute) {
        if (isset($attribute->id) && $attribute->id === 1000000001) {
          $color = [
            'name' => trim($attribute->value),
          ];
        }
      }
      unset($attribute);
      if (isset($color)) {
        $attr[$attribute_name] = $this->getAttributeColor($color, $type);

        $colorRadioId = reset($attr[$attribute_name]);
      }
    }

    if ($cProduct && isset($attr[$attribute_name])) {
      $cVariation = current(\Drupal::entityTypeManager()
        ->getStorage('commerce_product_variation')
        ->loadByProperties([
          'product_id'    => $cProduct->id(),
          $attribute_name => reset($attr[$attribute_name]),
        ]));

      if ($cVariation) {
        $attr['field_images'] = array_map(function (FieldItemInterface $item) {
          return $item->target_id;
        }, iterator_to_array($cVariation->get('field_images')));
      } else {
        $attr['field_images'] = CommerceOasis::getImages($product->images);
      }
    } else {
      $attr['field_images'] = CommerceOasis::getImages($product->images);
    }

    $variation = ProductVariation::create($attr);
    $variation->save();

    return $variation;
  }

  /**
   * @param $variation
   * @param $product
   * @param $type
   *
   * @throws EntityStorageException
   */
  public function editVariation($variation, $product, $type) {
    $productVariation = ProductVariation::load($variation->id());
    $productVariation->setPrice(new Price($this->getCalculationPrice($product), 'RUB'));
    $productVariation->set('field_body', $product->description);
    $productVariation->set('status', $this->getStatusAndStock($product)['status']);
    $productVariation->set('field_stock', $this->getStatusAndStock($product)['field_stock']);
    $productVariation->save();
  }

  /**
   * Get calculation price
   *
   * @param $product
   * @return float
   */
  public function getCalculationPrice($product): float
  {
    $price = !empty($this->calculation['dealer']) ? $product->discount_price : $product->price;

    if (!empty($this->calculation['factor'])) {
      $price = $price * (float)$this->calculation['factor'];
    }

    if (!empty($this->calculation['increase'])) {
      $price = $price + (float)$this->calculation['increase'];
    }

    return (float)$price;
  }

  /**
   * @param $product
   *
   * @return array
   */
  public function getStatusAndStock($product): array {
    if ($product->rating === 5) {
      $data['status'] = TRUE;
      $data['field_stock'] = [99999];
    } elseif (!is_null($product->total_stock)) {
      $data['status'] = TRUE;
      $data['field_stock'] = [$product->total_stock];
    } else {
      $data['status'] = FALSE;
      $data['field_stock'] = [0];
    }

    return $data;
  }

  /**
   * @param $product
   * @param $variation
   * @param $type
   *
   * @return Product|EntityBase|EntityInterface
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public function addProduct($product, $variation, $type) {
    $product = Product::create([
      'type' => $type,
      'title' => $product->name,
      'stores' => $this->defaultStore,
      'variations' => $variation,
      'field_brand' => is_null($product->brand) ? '' : CommerceOasis::getBrand($product->brand),
      'field_product_categories' => CommerceOasis::getCategories($product->full_categories, $this->categories),
      'field_group_id_oasis' => $product->group_id,
    ]);
    $product->save();

    return $product;
  }

  /**
   * @param $variation
   * @param $product
   */
  public function editProduct($variation, $product) {
    $product->addVariation($variation)->save();
  }

  /**
   * @param $data
   * @param string $type
   *
   * @return array
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public function getAttributeColor($data, string $type = 'other'): array {
    $productAttributeId = \Drupal::entityTypeManager()
      ->getStorage('commerce_product_attribute_value')
      ->loadByProperties(['name' => $data['name']]);

    if (!$productAttributeId) {
      $attr = [
        'name' => $data['name'],
      ];

      if ($type === 'clothing') {
        $attr['attribute'] = 'color';
        $attr['field_color'] = $data['value'];
      } elseif ($type === 'color') {
        $attr['attribute'] = 'color_radio';
      }

      $attribute = ProductAttributeValue::create($attr);
      $attribute->save();
      return [$attribute->id()];
    }

    return [array_key_first($productAttributeId)];
  }

  /**
   * @param string $value
   * @param string $attributeType
   *
   * @return array
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public static function getAttribute(string $value, string $attributeType): array {
    $productAttributeId = \Drupal::entityTypeManager()
      ->getStorage('commerce_product_attribute_value')
      ->loadByProperties(['name' => $value]);

    if (!$productAttributeId) {
      $attribute = ProductAttributeValue::create([
        'attribute' => $attributeType,
        'name' => $value,
      ]);
      $attribute->save();
      $result = [$attribute->id()];
    } else {
      $result = [array_key_first($productAttributeId)];
    }

    return $result;
  }

  /**
   * Download images, return ids
   *
   * @param $images
   *
   * @return array
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public static function getImages($images): array {
    $result = [];

    if (is_array($images)) {
      $default_scheme = \Drupal::config('system.file')->get('default_scheme');
      $folder = $default_scheme . '://product-images/';
      \Drupal::service('file_system')->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY);

      foreach ($images as $itemImage) {
        if (isset($itemImage->superbig)) {
          $basename = basename($itemImage->superbig);

          $img = \Drupal::entityTypeManager()
            ->getStorage('file')
            ->loadByProperties(['filename' => $basename]);

          if ($img) {
            $result[] = array_key_first($img);
          } else {
            $pic = file_get_contents($itemImage->superbig, TRUE, stream_context_create([
              'http' => [
                'ignore_errors' => TRUE,
                'follow_location' => TRUE,
              ],
            ]));

            if (preg_match("/200|301/", $http_response_header[0])) {
              $file = file_save_data($pic, $folder . $basename);
              $result[] = $file->id();
            }
          }
        }
      }
      unset($itemImage, $basename, $img);
    }

    return $result;
  }

  /**
   * Get term manufacturer and crated
   *
   * @param $name
   *
   * @return array
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public static function getBrand($name): array {
    $vid = 'brands';

    $term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['name' => $name]);

    if ($term) {
      $result = [array_key_first($term)];
    } else {
      $term = Term::create([
        'vid' => CommerceOasis::getVocabulary($vid),
        'name' => $name,
        'status' => 1,
        'description' => [
          'value' => '',
          'format' => 'full_html',
        ],
        'changed' => time(),
      ]);
      $term->save();

      $result = [$term->id()];
    }

    return $result;
  }

  /**
   * Get taxonomy category and crated
   *
   * @param $productCategories
   * @param $oasisCats
   *
   * @return array
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public static function getCategories($productCategories, $oasisCats): array {
    $idCategories = [];

    foreach ($productCategories as $productCategory) {
      $terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['field_id_oasis' => $productCategory]);

      if (!$terms) {
        foreach ($oasisCats as $oasisCatItem) {
          if ($oasisCatItem->id === $productCategory) {

            $idCategories[] = (int) CommerceOasis::addCategory($oasisCatItem, $oasisCats);
          }
        }
      } else {
        $idCategories[] = array_key_first($terms);
      }
    }

    return $idCategories;
  }

  /**
   * Add taxonomy category
   *
   * @param $category
   * @param $oasisCats
   *
   * @return int|mixed|string|null
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  public static function addCategory($category, $oasisCats) {
    $parent = 0;

    if (!is_null($category->parent_id)) {
      $parent = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['field_id_oasis' => $category->parent_id]);

      if (!$parent) {
        foreach ($oasisCats as $oasisCat) {
          if ($oasisCat->id === $category->parent_id) {
            $parent = CommerceOasis::addCategory($oasisCat, $oasisCats);
          }
        }
      } else {
        $parent = array_key_first($parent);
      }
    }

    $term = Term::create([
      'vid' => CommerceOasis::getVocabulary('product_categories'),
      'name' => $category->name,
      'status' => 1,
      'description' => [
        'value' => '',
        'format' => 'full_html',
      ],
      'changed' => time(),
      'depth_level' => $category->level,
      'parent' => [$parent],
      'field_id_oasis' => [$category->id],
    ]);
    $term->save();

    return $term->id();
  }

  /**
   * Get taxonomy manufacturer and crated
   *
   * @param $vid
   * @param string $name
   *
   * @return string
   * @throws EntityStorageException
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
   * @param array $args
   *
   * @return array
   */
  public function getOasisProducts(array $args = []): array
  {
    $args['fieldset'] = 'full';

    $data = [
      'currency'         => $this->config->get('oasis_currency'),
      'no_vat'           => $this->config->get('oasis_no_vat'),
      'not_on_order'     => $this->config->get('oasis_not_on_order'),
      'price_from'       => $this->config->get('oasis_price_from'),
      'price_to'         => $this->config->get('oasis_price_to'),
      'rating'           => $this->config->get('oasis_rating'),
      'warehouse_moscow' => $this->config->get('oasis_warehouse_moscow'),
      'warehouse_europe' => $this->config->get('oasis_warehouse_europe'),
      'remote_warehouse' => $this->config->get('oasis_remote_warehouse'),
    ];

    $categories = $this->config->get('oasis_categories');
    $categoryIds = [];

    if (!is_null($categories)) {
      $categories = array_values($categories);
      foreach ($categories as $category) {
        if ($category) {
          $categoryIds[] = $category;
        }
      }

      if (!count($categoryIds)) {
        $categoryIds = array_keys(CommerceOasis::getOasisMainCategories());
      }
    } else {
      $categoryIds = array_keys(CommerceOasis::getOasisMainCategories());
    }

    $data['category'] = implode(',', $categoryIds);

    unset($categoryIds, $category);

    foreach ($data as $key => $value) {
      if ($value) {
        $args[$key] = $value;
      }
    }

    return CommerceOasis::curlQuery('v4/', 'products', $args);
  }

  /**
   * @return array
   */
  public static function getOasisMainCategories(): array {
    $result = [];
    $categories = CommerceOasis::getOasisCategories();

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
  public static function getOasisCategories(): array {
    return CommerceOasis::curlQuery('v4/', 'categories', ['fields' => 'id,parent_id,root,level,slug,name,path']);
  }

  /**
   * @return array|false
   */
  public static function getOasisStock(): array {
    return CommerceOasis::curlQuery('v4/', 'stock', ['fields' => 'id,stock']);
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
        } else {
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

  /**
   * @param string $color
   *
   * @return false|string
   */
  public static function getHexColor(string $color) {
    $colors = [
      1470 => [
        'белый прозрачный',
        'белый',
        'матовый',
        'белый перламутр',
        'прозрачный',
        'слоновая кость',
        'белый натуральный',
        'бесцветный полупрозрачный',
      ],
      1471 => [
        'гранит',
        'графит',
        'антрацит',
        'черный',
        'черное дерево',
        'черный глянцевый',
        'черный металлик',
        'черный насыщенный',
        'черный полупрозрачный',
        'черный прозрачный',
        'угольный',
      ],
      1472 => [
        'джинсовый',
        'светло-синий',
        'ярко-синий',
        'баклажан',
        'синий',
        'деним',
        'синий классический',
        'синий меланж',
        'синий глубокий',
        'синий матовый',
        'синий металлик',
        'синий прозрачный',
        'стальной синий',
      ],
      1473 => [
        'темно-красный',
        'светло-красный',
        'красный меланж',
        'красный прозрачный',
        'красный',
      ],
      1474 => [
        'зеленый',
        'зеленый бутылочный',
        'бирюзовый',
        'зеленый армейский',
        'зеленый прозрачный',
        'светло-зеленый',
        'зеленый дымчатый',
        'жаде',
        'зеленый матовый',
        'зеленый меланж',
        'изумрудный',
        'оливковый',
        'салатовый',
        'хаки',
        'темно-зеленый',
        'фисташковый',
        'ярко-зеленый',
      ],
      1475 => [
        'неоновый зеленый',
        'лайм',
        'желто-зеленый',
        'зеленое яблоко',
      ],
      1476 => [
        'апельсин',
        'оранжевый',
        'медный',
        'оранжевый прозрачный',
        'рыжий',
        'неоновый оранжевый',
        'ярко-оранжевый',
        'оранжевый металлик',
      ],
      1477 => [
        'желтый прозрачный',
        'светло-желтый',
        'песочный',
        'песочный темный',
        'неоновый желтый',
        'желтый',
        'золотисто-желтый',
      ],
      1478 => [
        'темно-бордовый',
        'бордовый',
        'бордо',
        'бордовый металлик',
        'бургунди',
        'вишневый',
        'красное дерево',
        'светло-вишневый',
        'бордо золотистый',
      ],
      1479 => [
        'сиреневый прозрачный',
        'лиловый',
        'темно-фиолетовый',
        'сиреневый',
        'сливовый',
        'фиолетовый',
        'пурпурный',
      ],
      1480 => [
        'голубой',
        'аква',
        'голубой лед',
        'небесно-голубой',
        'серо-голубой',
        'лазурный',
        'небесно-синий',
        'морская волна',
        'светло-голубой',
      ],
      1481 => [
        'серебристый матовый',
        'серый прозрачный',
        'серый меланж',
        'светлый меланж',
        'серый стальной',
        'темно-стальной',
        'пепельный',
        'пепельно-серый',
        'светло-серый',
        'темно-серый',
        'стальной',
        'серый',
        'желтовато-серый',
      ],
      1482 => [
        'шоколад',
        'коричневый',
        'каштановый',
        'коричнево-серый',
        'дерево',
        'темно-коричневый',
        'светло-коричневый',
      ],
      1483 => [
        'молочный',
        'серо-бежевый',
        'мраморный',
        'кремовый',
        'натуральный',
        'грязно-бежевый',
        'бежевый',
        'темно-бежевый',
        'шампань',
      ],
      1484 => [
        'золотистый',
        'бронзовый',
        'кофейно-золотистый',
      ],
      1485 => [
        'металлик',
        'серебристый металлик',
        'серебристый прозрачный',
        'серебристый',
        'хром',
      ],
      1486 => [
        'радуга',
        'разноцветный',
      ],
      1487 => [
        'неоновый розовый',
        'розовый',
        'фуксия',
      ],
      1488 => [
        'темно-синий',
        'navy',
      ],
    ];

    foreach ($colors as $key => $value) {
      foreach ($value as $itemColor) {
        if (trim($itemColor) === $color) {
          return CommerceOasis::parentColor($key);
        }
      }
    }

    return FALSE;
  }

  /**
   * @param int $color_id
   *
   * @return string
   */
  public static function parentColor(int $color_id): string {
    $colors = [
      1470 => '#EFEFEF',
      1471 => '#000000',
      1472 => '#0000ff',
      1473 => '#ff0000',
      1474 => '#008000',
      1475 => '#5edc1f',
      1476 => '#ffa500',
      1477 => '#ffff00',
      1478 => '#9b2d30',
      1479 => '#8b00ff',
      1480 => '#42aaff',
      1481 => '#808080',
      1482 => '#964b00',
      1483 => '#f5f5dc',
      1484 => '#ffd700',
      1485 => '#c0c0c0',
      //1486 => '#Разноцветный',
      1487 => '#ffc0cb',
      1488 => '#002137',
    ];

    if (array_key_exists($color_id, $colors)) {
      return $colors[$color_id];
    }

    return $colors[1481];
  }

  /**
   * @param $secs
   *
   * @return string
   */
  public function secToHis($secs) {
    $res = [];
    $res['hours'] = floor($secs / 3600);
    $secs = $secs % 3600;

    $res['minutes'] = floor($secs / 60);
    $res['secs'] = $secs % 60;

    return $res['hours'] . ':' . $res['minutes'] . ':' . $res['secs'];
  }

}

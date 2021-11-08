<?php

namespace Drupal\commerce_oasis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Frontpage extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new Frontpage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Builds the frontpage.
   *
   * @return array
   *   A render array.
   */
  public function view() {
    $product_view_builder = $this->entityTypeManager->getViewBuilder('commerce_product');
    $build = [
      '#theme' => 'commerce_oasis_frontpage',
    ];

    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $product_ids = $product_storage->getQuery()
      ->condition('status', true)
      ->range(0, 6)
      ->sort('changed', 'DESC')
      ->execute();

//    $file_storage = \Drupal::entityTypeManager()->getStorage('commerce_product');
//    $file = $product_storage->loadMultiple();

    $featured_products = $product_storage->loadMultiple($product_ids);
    foreach ($featured_products as $product) {
      $build['#featured_products'][] = $product_view_builder->view($product, 'catalog');
    }

    return $build;
  }

}

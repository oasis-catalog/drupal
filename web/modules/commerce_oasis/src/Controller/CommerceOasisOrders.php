<?php
/**
 * @file
 * Contains \Drupal\commerce_oasis\Controller\CommerceOasisOrders.
 */

namespace Drupal\commerce_oasis\Controller;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AlertCommand;
use Drupal\Core\Ajax\InsertCommand;
use Drupal\Core\Controller\ControllerBase;

/**
 * Provides route for our commerce module.
 */
class CommerceOasisOrders extends ControllerBase
{

  /**
   * The config module Oasis.
   */
  private $config = NULL;

  /**
   * Constructs.
   */
  public function __construct()
  {
    $this->config = $this->config('commerce_oasis.settings');
  }

  public function ajaxSend($orderId)
  {
    $user_id = $this->config->get('oasis_api_user_id');
    $response = new AjaxResponse();

    if ($user_id) {
      $order = Order::load($orderId);
      $variations = $order->getItems();
      $data['userId'] = $user_id;

      foreach ($variations as $variation) {
        $products[] = $variation->getTitle();

        $query = \Drupal::database()
          ->select('commerce_product_variation__field_id_oasis', 'fido');
        $query->addField('fido', 'field_id_oasis_value');
        $query->condition('fido.entity_id', $variation->getPurchasedEntityId());
        $oasisProductId = $query->execute()->fetchField();

        $data['items'][] = [
          'productId' => $oasisProductId,
          'quantity'  => (int)$variation->getQuantity(),
        ];
      }

      $request = $this->sendOrder($data);

      if (isset($request->queueId)) {
        \Drupal::database()
          ->insert('commerce_order_oasis')
          ->fields(['order_id', 'queue_id'])
          ->values([
            'order_id' => $orderId,
            'queue_id' => $request->queueId,
          ])
          ->execute();

        $response->addCommand(new InsertCommand('.order-' . $orderId, '<p>Order exported, queue_id=' . $request->queueId . '</p>', $settings = NULL));
      } else {
        $response->addCommand(new AlertCommand('Error. Queue not added!'));
      }
    } else {
      $response->addCommand(new AlertCommand('Not user id'));
    }

    return $response;
  }

  /**
   * Builds the response.
   */
  public function build()
  {
    $build['content'] = [
      '#theme' => 'oasis_orders',
    ];

    $apiKey = $this->config->get('oasis_api_key');

    if (!empty($apiKey)) {
      $validate = CommerceOasis::getOasisCurrency();

      if ($validate) {
        $apiUserId = $this->config->get('oasis_api_user_id');

        if (!empty($apiUserId)) {
          $build['content']['#validate'] = TRUE;

          $orders = \Drupal::entityTypeManager()
            ->getStorage('commerce_order')
            ->getQuery()
            ->condition('state', 'fulfillment')
            ->execute();

          if ($orders) {
            foreach ($orders as $orderId) {
              $products = [];
              $order = Order::load($orderId);
              $items = $order->getItems();

              foreach ($items as $item) {
                $products[] = $item->getTitle();
              }
              unset($items, $item);

              $queueQuery = \Drupal::database()
                ->select('commerce_order_oasis', 'os');
              $queueQuery->addField('os', 'queue_id');
              $queueQuery->condition('os.order_id', $order->getOrderNumber());
              $orderQueue = $queueQuery->execute()->fetchField();

              $build['content']['#orders'][] = [
                'id'         => $order->getOrderNumber(),
                'totalSumma' => $order->getTotalPrice()->getNumber(),
                'products'   => $products,
                'queue'      => $orderQueue,
              ];
            }
          } else {
            $this->messenger()->addMessage($this->t('Not orders!'), 'status');
          }
        } else {
          $this->messenger()->addMessage($this->t('Warning! Not User Id!'), 'warning');
        }
      } else {
        $this->messenger()->deleteByType('error');
        $this->messenger()->addMessage($this->t('Error Unauthorized. Invalid API key!'), 'error');
      }
    } else {
      $this->messenger()->deleteByType('error');
      $this->messenger()->addMessage($this->t('Error! Not API key!'), 'error');
    }

    return $build;
  }

  protected function sendOrder($data)
  {
    $apiKey = $this->config->get('oasis_api_key');

    try {
      $options = [
        'http' => [
          'method' => 'POST',
          'header' => 'Content-Type: application/json' . PHP_EOL .
            'Accept: application/json' . PHP_EOL,

          'content' => json_encode($data),
        ],
      ];
      return json_decode(file_get_contents('https://api.oasiscatalog.com/v4/reserves/?key=' . $apiKey, 0, stream_context_create($options)));

    } catch (\Exception $exception) {
      return FALSE;
    }
  }

}

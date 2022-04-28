<?php

namespace Drupal\commerce_oasis\Commands;

use Drush\Commands\DrushCommands;
use Drupal\commerce_oasis\Controller\CommerceOasis;

/**
 * A Drush command file
 */
class BatchCommands extends DrushCommands {

  /**
   * Run file cron import products or update quantity
   *
   * @command oasis
   * @aliases oasis
   * @option stock to update the quantity.
   *
   * @usage oasis --stock
   */
  public function doExecute($option = ['stock' => FALSE]) {
    $oasis = new CommerceOasis();
    $oasis->doExecuteImport($option['stock'], true);
  }

}

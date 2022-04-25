<?php

namespace Drupal\commerce_oasis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_oasis\Controller\CommerceOasis;

class OasisSettingsForm extends ConfigFormBase {

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'oasis_admin_settings';
  }

  /**
   * {@inheritDoc}
   */
  public function getEditableConfigNames() {
    return [
      'commerce_oasis.settings',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_oasis.settings');
    $apiKey = $config->get('oasis_api_key');

    $form['oasis_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $apiKey,
    ];

    $form['oasis_api_user_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API User ID'),
      '#default_value' => $config->get('oasis_api_user_id'),
    ];

    if ($apiKey && $apiKey !== '') {
      $currecies = CommerceOasis::getOasisCurrency();

      if ($currecies) {
        $form['oasis_currency'] = [
          '#type' => 'select',
          '#title' => $this->t('Валюта'),
          '#options' => $currecies,
          '#default_value' => $config->get('oasis_currency'),
        ];

        $form['oasis_categories'] = [
          '#type' => 'checkboxes',
          '#options' => CommerceOasis::getOasisMainCategories(),
          '#title' => $this->t('Категории'),
          '#default_value' => $config->get('oasis_categories') ? array_values($config->get('oasis_categories')) : [],
        ];

        $form['oasis_no_vat'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Без НДС'),
          '#default_value' => $config->get('oasis_no_vat'),
        ];

        $form['oasis_not_on_order'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Под заказ'),
          '#default_value' => $config->get('oasis_not_on_order'),
        ];

        $form['oasis_price_from'] = [
          '#type' => 'number',
          '#title' => $this->t('Цена от'),
          '#default_value' => $config->get('oasis_price_from'),
        ];

        $form['oasis_price_to'] = [
          '#type' => 'number',
          '#title' => $this->t('Цена до'),
          '#default_value' => $config->get('oasis_price_to'),
        ];

        $form['oasis_rating'] = [
          '#type' => 'select',
          '#title' => $this->t('Тип'),
          '#options' => [
            1 => $this->t('Только новинки'),
            2 => $this->t('Только хиты'),
            3 => $this->t('Только со скидкой'),
          ],
          '#empty_option' => $this->t('-Выбрать-'),
          '#default_value' => $config->get('oasis_rating'),
        ];

        $form['oasis_warehouse_moscow'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('На складе в Москве'),
          '#default_value' => $config->get('oasis_warehouse_moscow'),
        ];

        $form['oasis_warehouse_europe'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('На складе в Европе'),
          '#default_value' => $config->get('oasis_warehouse_europe'),
        ];

        $form['oasis_remote_warehouse'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('На удаленном складе'),
          '#default_value' => $config->get('oasis_remote_warehouse'),
        ];

        $form['oasis_limit'] = [
          '#type' => 'number',
          '#title' => $this->t('Лимит'),
          '#default_value' => $config->get('oasis_limit'),
        ];

        $form['oasis_factor'] = [
          '#type' => 'number',
          '#title' => $this->t('Коэффициент цены'),
          '#default_value' => $config->get('oasis_factor'),
          '#step' => '0.01',
        ];

        $form['oasis_increase'] = [
          '#type' => 'number',
          '#title' => $this->t('Надбавка к цене'),
          '#default_value' => $config->get('oasis_increase'),
          '#step' => '0.01',
        ];

        $form['oasis_dealer'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Использовать диллерские цены'),
          '#default_value' => $config->get('oasis_dealer'),
        ];

        $form['import_run'] = [
          '#type' => 'details',
          '#title' => $this->t('Run import manually'),
          '#open' => TRUE,
        ];

        $form['import_run']['import_trigger']['actions'] = [
          '#type' => 'actions',
          'sumbit' => [
            '#type' => 'submit',
            '#value' => $this->t('Run import now'),
            '#submit' => [[$this, 'importRun']],
          ],
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function importRun() {
    $oasis = new CommerceOasis;
    $oasis->doExecuteImport(false);

    $msg = 'Variation added id = ';

    $this->messenger()
      ->addMessage($this->t($msg));
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('commerce_oasis.settings')
      ->set('oasis_api_key', $form_state->getValue('oasis_api_key'))
      ->set('oasis_api_user_id', $form_state->getValue('oasis_api_user_id'))
      ->set('oasis_currency', $form_state->getValue('oasis_currency'))
      ->set('oasis_categories', $form_state->getValue('oasis_categories'))
      ->set('oasis_no_vat', $form_state->getValue('oasis_no_vat'))
      ->set('oasis_not_on_order', $form_state->getValue('oasis_not_on_order'))
      ->set('oasis_price_from', $form_state->getValue('oasis_price_from'))
      ->set('oasis_price_to', $form_state->getValue('oasis_price_to'))
      ->set('oasis_rating', $form_state->getValue('oasis_rating'))
      ->set('oasis_warehouse_moscow', $form_state->getValue('oasis_warehouse_moscow'))
      ->set('oasis_warehouse_europe', $form_state->getValue('oasis_warehouse_europe'))
      ->set('oasis_remote_warehouse', $form_state->getValue('oasis_remote_warehouse'))
      ->set('oasis_limit', $form_state->getValue('oasis_limit'))
      ->set('oasis_step', 0)
      ->set('oasis_factor', $form_state->getValue('oasis_factor'))
      ->set('oasis_increase', $form_state->getValue('oasis_increase'))
      ->set('oasis_dealer', $form_state->getValue('oasis_dealer'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

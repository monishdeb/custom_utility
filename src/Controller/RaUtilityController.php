<?php

namespace Drupal\ra_custom_utility\Controller;

use Drupal\Core\Controller\ControllerBase;
use Dompdf\Dompdf;

class RaUtilityController extends ControllerBase {

  public function getStates() {

    $form = array (
      '#type' => 'select',
      '#title' => t('State/Province'),
      '#options' => $this->get_province_list($_POST['country_id']),
      '#prefix' => '<div id="states-wrapper">',
      '#suffix' => '</div>',
      '#attributes' => [
        'id' => 'edit-state-province-id',
        'name' => 'state_province_id',
        'class' => "form-select",
        'data-drupal-selector' => "edit-state-province-id",
      ]
    );
    return $form;
  }

  /*
   * Getting civicrm state/province list from a country
   */
  public function get_province_list($country_id = 0) {
    $state_list = array('- Any -');

    if(!$country_id) {
      return $state_list;
    }

    // Initialize CiviCRM.
    \Drupal::service('civicrm')->initialize();
      
    try{
      $result = civicrm_api3('StateProvince', 'get', array('country_id' => $country_id, 'version' => 3, 'options' => array('limit' => 0, 'sort' => "name ASC")));
      if(!empty($result['values'])) {
        foreach($result['values'] as $state) {
          $state_list[$state['id']] = $state['name'];
        }
      }
    } catch (CiviCRM_API3_Exception $e) {
      $error_message = array(
                          'error_message' => $e->getMessage(),
                          'error_code' => $e->getErrorCode(),
                          'error_data' => $e->getExtraParams(),
                        );
      \Drupal::logger('ra_custom_utility')->error(print_r($error_message, true));
    }
    return $state_list;
  }
}
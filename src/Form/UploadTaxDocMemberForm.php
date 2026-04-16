<?php

/**
 * @file
 * Contains \Drupal\ra_custom_utility\Form\UploadTaxDocMemberForm.
 */

namespace Drupal\ra_custom_utility\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;

class UploadTaxDocMemberForm extends FormBase {

  public $uniqueIdentifier = 'member';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tax_doc_upload_' . $this->uniqueIdentifier;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user = 0) {
    $param = \Drupal::request()->query->all();
    $exp_param = explode('/', $param['dest']);
    if (isset($param)) {
      $session = new \Symfony\Component\HttpFoundation\Session\Session();
      $session->set('query_params', $param);
    }
    $wrapper = 'ajax-wrapper-' . $this->uniqueIdentifier;
    $form['#prefix'] = '<div id="' . $wrapper . '">';
    $form['#suffix'] = '</div>';
    $form['uc_up_file'] = array(
        '#type' => 'managed_file',
        '#title' => t('Click browse to upload your tax exempt document:'),
        '#description' => t('Maximum file size: 256 MB Allowed extensions: txt doc docx pdf'),
        '#required' => TRUE,
        '#upload_validators' => array(
            'file_validate_extensions' => array('txt doc docx pdf'),
            'file_validate_size' => array(256 * 1024 * 1024),
        ),
    );
    $form['member_id'] = array(
        '#type' => 'hidden',
        '#value' => $user,
    );
    $form['order_id'] = array(
        '#type' => 'hidden',
        '#value' => $exp_param[4],
    );
    $form['last_page'] = array(
        '#type' => 'hidden',
        '#value' => isset($_GET['dest']) ? $_GET['dest'] : '',
    );


//Constructing a URL.Will redirect to example-page with query paramter q = 5.
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Save')
    );
    #$form['#attached']['library'][] = 'ra_custom_ubercart/ra_custom_ubercart';
    #$form['#attached']['library'][] = 'ra_custom_ubercart/ra_custom_ubercart';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $title = $form_state->getValue('member_id');
    if (!is_numeric($title[0]['value']) || $title[0]['value'] == 0) {
      $form_state->setErrorByName('uc_up_file', t('Invalid member token.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    \Drupal::service('civicrm')->initialize();
    $current_user_id = $form_state->getValue('member_id');
    $last_page_id = $form_state->getValue('last_page');
    $order_id = $form_state->getValue('order_id');
    $response = Url::fromUserInput($last_page_id);
    $form_state->setRedirectUrl($response);
    $civicrm_id = get_civiid($current_user_id);
    if (isset($order_id)) {
      $order = \Drupal\commerce_order\Entity\Order::load($order_id);
    }
    // If no civicrm profile exists for the user create new and attach with the drupal user id
    if (empty($civicrm_id)) {
      $order_email = $order->getEmail();
      // Check whether the same email exists in the civicrm
      if (!empty($order_email)) {
        $email_exists_check = array('email' => $order_email);
        $existing_contact = civicrm_api3('Contact', 'Get', $email_exists_check);
        if (empty($existing_contact['values'])) {
          $params = [
              'contact_type' => 'Individual',
              'email' => $order_email,
          ];
          $result = civicrm_api3('Contact', 'create', $params);          
          $civicrm_id = $result['id'];          
          $result = civicrm_api3('UFMatch', 'create', [
              'contact_id' => $civicrm_id,
              'uf_id' => $current_user_id, // Drupal user_id
              'uf_name' => $order_email,
          ]);          
        }
      }
    }
    if (isset($civicrm_id) && !empty($civicrm_id)) {      
      $uploaded_file = $form_state->getValue('uc_up_file');
      $file = \Drupal\file\Entity\File::load($uploaded_file[0]);
      $config = \CRM_Core_Config::singleton();
      $newName = \CRM_Utils_File::makeFileName($file->getFilename());
      $upload = copy($file->getFileUri(), $config->customFileUploadDir . $newName);
      $file_params = array(
          'version' => 3,
          'mime_type' => $file->getMimeType(),
          'name' => $newName,
          'uri' => $newName,
          'upload_date' => date('Y-m-d h:i:s'),
      );
      $file_save = civicrm_api('File', 'Create', $file_params);
      $file_save_id = $file_save['id'];
      $database_obj = \Drupal::database();
      $select = $database_obj->select('{civicrm_value_constituent_information_1}', 'civi_consti');
      $select->addExpression('COUNT(entity_id)');
      $tax_exempt = $select->condition('entity_id', $civicrm_id, '=')->execute()->fetchField();
      if ($tax_exempt > 0) {
        $database_obj->update('civicrm_value_constituent_information_1')
                ->fields(array('tax_exempt_approved_by_ra__63' => 'Yes', 'tax_exempt_document_316' => $file_save_id))
                ->condition('entity_id', $civicrm_id)
                ->execute();
      } else {
        $database_obj->insert('civicrm_value_constituent_information_1')
                ->fields([
                    'entity_id',
                    'tax_exempt_approved_by_ra__63',
                    'tax_exempt_document_316',
                ])
                ->values(array(
                    $civicrm_id,
                    'Yes',
                    $file_save_id,
                ))
                ->execute();
      }
      $database_obj->insert('civicrm_entity_file')
              ->fields([
                  'entity_table',
                  'entity_id',
                  'file_id',
              ])
              ->values(array(
                  'civicrm_value_constituent_information_1',
                  $civicrm_id,
                  $file_save_id,
              ))
              ->execute();
      // Remove the ny/nz sales tax from the order if it exists      
      foreach ($order->collectAdjustments() as $Adjustment) {
        //foreach ($order_item->getAdjustments() as $Adjustment) {
        if ($Adjustment->getType() == 'tax') {
          $order->removeAdjustment($Adjustment);
          _clear_entity_db_cache();
          //  }
        }
      }
      $order->save();
      drupal_set_message(t('Your have successfully uploaded the document.'));
      /* // Send email to admin
        $mailManager = \Drupal::service('plugin.manager.mail');
        $langcode = \Drupal::currentUser()->getPreferredLangcode();
        $send = true;
        $key = 'logged_in_user_tax_doc_upload';
        $to = 'manoj@jbkinfotech.com';
        $user_mail = \Drupal::currentUser()->getEmail();
        $user_name = \Drupal::currentUser()->getUsername();
        global $base_url;
        #$to = 'KBarbieri@ghsoho.com';
        $a_message = 'Hi, ' . "\n\n" . $user_name . ' submitted the tax exemption document.<br><br><a href="' . $base_url . '/user/' . $current_user_id . '"> Click here </a>to view the documents';
        $a_message .= '<br/>' . '<a href="' . $base_url . '/user/taxexempt?c_uid=' . $current_user_id . '&tx_val=Yes">Click here </a> to verify. ';
        $a_message .= '<br/>' . '<br/><a href="' . $base_url . '/user/taxexempt?c_uid=' . $current_user_id . '&tx_val=No">Click here </a> to reject. </br></br>';
        $a_message .= "<br/>" . 'You may go to admin > edit this user civi profile > and set the field (tax exempted?) yes or no. ';
        $params['message'] = $a_message;
        $result = $mailManager->mail('ra_custom_ubercart', $key, $to, $langcode, $params, NULL, $send);
        if ($result['result'] !== true) {
        drupal_set_message(t('There was a problem sending your message and it was not sent.'), 'error');
        } else {
        drupal_set_message(t('Your message has been sent.'));
        }
        $database_obj->insert('civicrm_entity_file')
        ->fields([
        'entity_table',
        'entity_id',
        'file_id',
        ])
        ->values(array(
        'civicrm_value_constituent_information_1',
        $civicrm_id,
        $file_save_id,
        ))
        ->execute(); */
    }
  }

}

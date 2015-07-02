<?php

/* 
 * CiviCRM Offline Recurring Payment Extension for CiviCRM - Circle Interactive 2013
 * Original author: rajesh
 * http://sourceforge.net/projects/civicrmoffline/
 * Converted to Civi extension by andyw@circle, 07/01/2013
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html
 *
 * 
 * BOS1312346 - add earmarking to Recurring Contribution
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 27 Mar 2014
 */
require_once 'CRM/Core/Form.php';
require_once 'CRM/Core/Session.php';
require_once 'CRM/Contribute/DAO/ContributionRecur.php';

/**
 * This class provides the functionality to delete a group of
 * contacts. This class provides functionality for the actual
 * addition of contacts to groups.
 */
class Recurring_Form_RecurringContribution extends CRM_Core_Form {

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {
    parent::preProcess();
  }

  /**
   * Build the form
   *
   * @access public
   * @return void
   */
  function buildQuickForm() {

    $attributes = CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_ContributionRecur');
    $action = @$_GET['action'];
    $cid = CRM_Utils_Request::retrieve('cid', 'Integer', $this);
    $id = CRM_Utils_Request::retrieve('id', 'Integer', $this);

    require_once 'api/api.php';
    $result = civicrm_api('contact', 'get',
      array(
        'version' => 3,
        'id' => $cid
      )
    );
    if ($result['is_error']) {
      CRM_Core_Error::fatal('Unable to query contact id in ' . __FILE__ . ' at line ' . __LINE__);
    }
    else {
      $contact_details = reset($result['values']);
    }

    CRM_Utils_System::setTitle('Setup Recurring Payment - ' . $contact_details['display_name']);

    if ($action == 'update') {

      $dao = CRM_Core_DAO::executeQuery("
                SELECT ccr.*, ccro.* FROM civicrm_contribution_recur ccr
             LEFT JOIN civicrm_contribution_recur_offline ccro ON ccro.recur_id = ccr.id
                 WHERE id = %1
            ",
        array(1 => array($id, 'Integer'))
      );

      if ($dao->fetch()) {
        $defaults = array(
          'amount' => $dao->amount,
          'frequency_interval' => $dao->frequency_interval,
          'frequency_unit' => $dao->frequency_unit,
          'start_date' => $dao->start_date,
          'processor_id' => $dao->processor_id,
          'next_sched_contribution_date' => $dao->next_sched_contribution_date,
          'end_date' => $dao->end_date,
          'recur_id' => $dao->id,
          'maximum_amount' => $dao->maximum_amount,
          'payment_type' => $dao->payment_type_id,
          /*
           * BOS1312346
           */
          'earmarking' => $dao->earmarking_id,
          'notification_for_bank' => $dao->notification_for_bank
          //'standard_price'=>$dao->standard_price ,
          //'vat_rate'=>$dao->vat_rate
        );

        if (CRM_Utils_Array::value('start_date', $defaults) && !empty($dao->start_date) && $dao->start_date != '0000-00-00') {
          list($defaults['start_date'], $defaults['start_date_time'])
            = CRM_Utils_Date::setDateDefaults($defaults['start_date'], 'activityDate');
        }
        else {
          $defaults['start_date'] = "";
        }
        if (CRM_Utils_Array::value('next_sched_contribution_date', $defaults) && !empty($dao->next_sched_contribution_date) && $dao->next_sched_contribution_date != '0000-00-00') {
          list($defaults['next_sched_contribution_date'], $defaults['next_sched_contribution_date_time'])
            = CRM_Utils_Date::setDateDefaults($defaults['next_sched_contribution_date'], 'activityDate');
        }
        else {
          $defaults['next_sched_contribution_date'] = "";
        }
        if (CRM_Utils_Array::value('end_date', $defaults) && !empty($dao->start_date) && $dao->start_date != '0000-00-00') {
          list($defaults['end_date'], $defaults['end_date_time'])
            = CRM_Utils_Date::setDateDefaults($defaults['end_date'], 'activityDate');
        }
        else {
          $defaults['end_date'] = "";
        }
      }
      $this->addElement('hidden', 'recur_id', $id);
    }

    $this->add('text', 'amount', ts('Amount'), array(), TRUE);
    $this->add('text', 'frequency_interval', ts('Every'), array(
      'maxlength' => 2,
      'size' => 2
    ), TRUE);
    //$form->addRule( 'frequency_interval',
    //                        ts( 'Frequency must be a whole number (EXAMPLE: Every 3 months).' ), 'integer' );

    $frUnits = implode(CRM_Core_DAO::VALUE_SEPARATOR, CRM_Core_OptionGroup::values('recur_frequency_units'));
    $units = array();
    $unitVals = explode(CRM_Core_DAO::VALUE_SEPARATOR, $frUnits);

    $frequencyUnits = CRM_Core_OptionGroup::values('recur_frequency_units');

    foreach ($unitVals as $key => $val) {
      if (isset($frequencyUnits[$val])) {
        $units[$val] = $frequencyUnits[$val];
      }
    }

    $frequencyUnit = &$this->add('select', 'frequency_unit', NULL, $units, TRUE);

    // get recurring payment types ..
    $payment_types = array(0 => ts('Select...')) + _recurring_getPaymentTypes();

    $this->addDate('start_date', ts('Start Date'), TRUE, array('formatType' => 'activityDate'));
    $this->addDate('next_sched_contribution_date', ts('Next Scheduled Date'), TRUE, array('formatType' => 'activityDate'));
    $this->addDate('end_date', ts('End Date'), FALSE, array('formatType' => 'activityDate'));

    $this->add('text', 'maximum_amount', ts('Maximum Amount'), array(), TRUE);
    $this->add('select', 'payment_type', ts('Payment Type'), $payment_types, TRUE);
    /*
     * BOS1312346
     */
    $earmarkings = array(0 => ts('Select...')) + _recurring_getOptionList('earmarking');
    $this->add('select', 'earmarking', ts('Earmarking'), $earmarkings, TRUE);
    
    $options = array();
    $options[] = &HTML_QuickForm::createElement('radio', NULL, NULL, 'Yes', 1);
    $options[] = &HTML_QuickForm::createElement('radio', NULL, NULL, 'No', 0);

    $this->addGroup($options, 'notification_for_bank', ts('Notification for bank'));

    if (isset($defaults)) {
      $this->setDefaults($defaults);
    }

    $this->addElement('hidden', 'action', $action);
    $this->addElement('hidden', 'cid', $cid);

    $this->assign('cid', $cid);

    //$this->addFormRule( array( 'CRM_Package_Form_Package', 'formRule' ) );

    $this->addButtons(
      array(
        array(
          'type' => 'next',
          'name' => ts('Save'),
          'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
          'isDefault' => TRUE
        ),
      )
    );
  }

  /**
   * global validation rules for the form
   *
   * @param array $fields posted values of the form
   *
   * @return array list of errors to be posted back to the form
   * @static
   * @access public
   */
  static function formRule($values) {
    $errors = array();

    if (!empty($values['start_date']) && !empty($values['end_date'])) {
      $start = CRM_Utils_Date::processDate($values['start_date']);
      $end = CRM_Utils_Date::processDate($values['end_date']);
      if (($end < $start) && ($end != 0)) {
        $errors['end_date'] = ts('End date should be after Start date');
      }
    }
    return $errors;
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   * @return void
   * @throws Exception on error
   */
  public function postProcess() {
    $config =& CRM_Core_Config::singleton();
    $params = $this->controller->exportValues();
    $params['recur_id'] = $this->get('id');

    if (!empty($params['start_date'])) {
      $start_date = CRM_Utils_Date::processDate($params['start_date']);
    }
    if (!empty($params['end_date'])) {
      $end_date = CRM_Utils_Date::processDate($params['end_date']);
    }
    if (!empty($params['next_sched_contribution_date'])) {
      $next_sched_contribution_date = CRM_Utils_Date::processDate($params['next_sched_contribution_date']);
    }
    $params['cycle_day'] = date('d', strtotime($next_sched_contribution_date));

    $contributionRecur = array();
    $contributionRecur['amount'] = $params['amount'];
    $contributionRecur['frequency_interval'] = $params['frequency_interval'];
    $contributionRecur['frequency_unit'] = $params['frequency_unit'];
    $contributionRecur['start_date'] = $start_date;
    $contributionRecur['next_sched_contribution_date'] = $next_sched_contribution_date;
    $contributionRecur['cycle_day'] = date('d', strtotime($next_sched_contribution_date));
    if (isset($end_date)) {
      $contributionRecur['end_date'] = $end_date;
    }
    /*
     * BOS1312346 set financial type based on earmarking
     */
    $finTypeId = 1;
    if (isset($params['earmarking'])) {
      $finTypeId = _recurring_getFinType($params['earmarking']);
    }
    $contributionRecur['financial_type_id'] = $finTypeId;
    $params['financial_type_id'] = $finTypeId;
    // end BOS1312346

    if ($params['action'] == 'add') {
      $invoice_id = md5(uniqid(rand(), TRUE));
      $contributionRecur['contact_id'] = $params['cid'];
      $contributionRecur['invoice_id'] = $invoice_id;
      $contributionRecur['trxn_id'] = $invoice_id;
      $contributionRecur['currency'] = $config->defaultCurrency;
      $contributionRecur['create_date'] = date('YmdHis');

      $objectRef = CRM_Contribute_BAO_ContributionRecur::add($contributionRecur);
      $recur_id = $objectRef->id;
      $status = ts('Recurring Contribution setup successfully');
    } elseif ($params['action'] == 'update') {
      $contributionRecur['id'] = $params['recur_id'];
      $contributionRecur['modified_date'] = date('YmdHis');
      $objectRef = CRM_Contribute_BAO_ContributionRecur::add($contributionRecur);
      $status = ts('Recurring Contribution updated');
    }

    /*
     * BOS1312346 add earmarking to recurring contribution offline
     */
    if (empty($params['notification_for_bank'])) {
      $params['notification_for_bank'] = 0;
    }
    $recurOfflineSql = "
            REPLACE INTO civicrm_contribution_recur_offline 
              (recur_id, maximum_amount, payment_type_id, notification_for_bank, earmarking_id) 
            VALUES 
              (%1, %2, %3, %4, %5)
        ";
    $recurOfflineParams = array(
      1 => array($recur_id, 'Integer'),
      2 => array($params['maximum_amount'], 'Money'),
      3 => array($params['payment_type'], 'Integer'),
      4 => array($params['notification_for_bank'], 'Integer'),
      5 => array($params['earmarking'], 'Integer')
    );
    CRM_Core_DAO::executeQuery($recurOfflineSql, $recurOfflineParams);

    // additional lookahead functionality to create contributions 6 weeks in advance
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'Lookahead.php';
    $lookahead = new Recurring_Form_Lookahead();
    if ($params['action'] == 'add') {
      $params['recur_id'] = $recur_id;
      $lookahead->contributionCreate($params);
    }
    else {
      $lookahead->contributionUpdate($params);
    }

    CRM_Core_Session::setStatus($status);
    CRM_Utils_System::redirect(
      CRM_Utils_System::url('civicrm/contact/view', 'selectedChild=contribute&reset=1&cid=' . $params['cid'], FALSE, NULL, FALSE, TRUE)
    );

  }
}

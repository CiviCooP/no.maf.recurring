<?php

/*
 * CiviCRM Offline Recurring Payment Extension for CiviCRM - Circle Interactive 2013
 * Original author: rajesh
 * http://sourceforge.net/projects/civicrmoffline/
 * Converted to Civi extension by andyw@circle, 07/01/2013
 *
 * This is a customized version of the original extension to provide recurring payment functionality for the
 * MAF Project Norwegian banking system integration - andyw@circle, 02/09/2013
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html
 *
 */
/**
 * Issue BOS1312355 - add end_date to recurring contribution page
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 4 March 2014
 */
/**
 * Issue BOS1312346 add default earmarking and financial type when creating 
 * contribution in function recurring_process_offline_recurring payments (cron job)
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 31 Mar 2014
 */

define('MAF_RECURRING_DAYS_LOOKAHEAD', 60);
/*
 * BOS1405148 define constant for top level donor journey group
 */
if (!defined('MAF_DONORJOURNEY_GROUP')) {
  define('MAF_DONORJOURNEY_GROUP', 6509);
}

// Early 4.3 versions do not include civicrm_api3 wrapper
if (!class_exists('CiviCRM_API3_Exception')) {

    class CiviCRM_API3_Exception extends Exception {

        private $extraParams = array();

        public function __construct($message, $error_code, $extraParams = array(),Exception $previous = null) {
            parent::__construct(ts($message));
            $this->extraParams = $extraParams + array('error_code' => $error_code);
        }

        // custom string representation of object
        public function __toString() {
            return __CLASS__ . ": [{$this->extraParams['error_code']}: {$this->message}\n";
        }

        public function getErrorCode() {
            return $this->extraParams['error_code'];
        }

        public function getExtraParams() {
            return $this->extraParams;
        }

    }

}

if (!function_exists('civicrm_api3')) {

    function civicrm_api3($entity, $action, $params = array()) {
        $params['version'] = 3;
        $result = civicrm_api($entity, $action, $params);
        if(is_array($result) && !empty($result['is_error'])){
            throw new CiviCRM_API3_Exception($result['error_message'], CRM_Utils_Array::value('error_code', $result, 'undefined'), $result);
        }
        return $result;
    }
}

function recurring_civicrm_config(&$config) {

    $template = &CRM_Core_Smarty::singleton();
    $ddRoot   = dirname(__FILE__);
    $ddDir    = $ddRoot . DIRECTORY_SEPARATOR . 'templates';

    if (is_array($template->template_dir)) {
        array_unshift($template->template_dir, $ddDir);
    } else {
        $template->template_dir = array($ddDir, $template->template_dir);
    }

    // also fix php include path
    $include_path = $ddRoot . PATH_SEPARATOR . get_include_path();
    set_include_path($include_path);

}

function recurring_civicrm_disable() {

    /* Temp - do not remove table in production ... */
    //CRM_Core_DAO::executeQuery("DROP TABLE civicrm_contribution_recur_offline");
    /* End of temporary code */

    CRM_Core_DAO::executeQuery("
        DELETE FROM civicrm_job WHERE api_action = 'process_offline_recurring_payments'
    ");

    // Remove option group / values for recurring_payment_type
    $recurring_payment_type_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_option_group WHERE name = 'recurring_payment_type'"
    );

    if ($recurring_payment_type_id) {

        CRM_Core_DAO::executeQuery(
            "DELETE FROM civicrm_option_value WHERE option_group_id = %1",
            array(
               1 => array($recurring_payment_type_id, 'Positive')
            )
        );

        CRM_Core_DAO::executeQuery(
            "DELETE FROM civicrm_option_group WHERE id = %1",
            array(
               1 => array($recurring_payment_type_id, 'Positive')
            )
        );

    }

}

function recurring_civicrm_enable() {

    // Create entry in civicrm_job table for cron call
    $version = _recurring_getCRMVersion();

    if ($version >= 4.3) {
        // looks like someone finally wrote an api ..
        civicrm_api('job', 'create', array(
            'version'       => 3,
            'name'          => ts('Process Offline Recurring Payments'),
            'description'   => ts('Processes any offline recurring payments that are due'),
            'run_frequency' => 'Daily',
            'api_entity'    => 'job',
            'api_action'    => 'process_offline_recurring_payments',
            'is_active'     => 0
        ));
    } else {
        // otherwise, this ..
        CRM_Core_DAO::executeQuery("
            INSERT INTO civicrm_job (
               id, domain_id, run_frequency, last_run, name, description,
               api_prefix, api_entity, api_action, parameters, is_active
            ) VALUES (
               NULL, %1, 'Daily', NULL, 'Process Offline Recurring Payments',
               'Processes any offline recurring payments that are due',
               'civicrm_api3', 'job', 'process_offline_recurring_payments', '', 0
            )
            ", array(
                1 => array(CIVICRM_DOMAIN_ID, 'Integer')
            )
        );
    }

    // Table to keep track of additional recurring fields ..
    /*
     * BOS1312346 Earmarking (add field earmarking_id)
     */
    if (!CRM_Core_DAO::checkTableExists('civicrm_contribution_recur_offline')) {
        CRM_Core_DAO::executeQuery("
            CREATE TABLE IF NOT EXISTS `civicrm_contribution_recur_offline` (
              `recur_id` int(10) unsigned NOT NULL,
              `maximum_amount` decimal(20,2) unsigned NOT NULL,
              `payment_type_id` smallint(5) unsigned NOT NULL,
              `earmarking_id` smallint(5) unsigned DEFAULT NULL,
              `notification_for_bank` tinyint(1) NOT NULL,
              `activity_id` int(10) unsigned NOT NULL,
              PRIMARY KEY (`recur_id`),
              KEY `activity_id` (`activity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
        ");
    } else {
        if (!CRM_Core_DAO::checkFieldExists('civicrm_contribution_recur_offline', 'earmarking_id')) {
            CRM_Core_DAO::executeQuery("
                ALTER TABLE civicrm_contribution_recur_offline ADD COLUMN 
                earmarking_id SMALLINT(5) UNSIGNED DEFAULT NULL AFTER payment_type_id");
        }
    }


    // TEMP - perform table upgrades to previous versions
    //CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_contribution_recur_offline` CHANGE `maximum_amount` `maximum_amount` DECIMAL( 20, 2 ) UNSIGNED NOT NULL ");
    //CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_contribution_recur_offline` ADD `mailing_id` INT UNSIGNED DEFAULT NULL AFTER `recur_id`");
    //CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_contribution_recur_offline` ADD INDEX `mailing_id` (`mailing_id`)");

    // Create option group for payment types
    CRM_Core_DAO::executeQuery("
        INSERT INTO civicrm_option_group (id, name, title, description, is_reserved, is_active)
        VALUES (NULL, 'recurring_payment_type', 'Payment Type', 'Payment types for Nets banking integration', '1', '1')
    ");
    $recurring_payment_type_id = CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');

    // Create option values
    CRM_Core_DAO::executeQuery("
        INSERT INTO civicrm_option_value (id, option_group_id, label, value, filter, weight, is_optgroup, is_reserved, is_active)
        VALUES (NULL, %1, %2, '1', 0, 1, 0, 1, 1),
               (NULL, %1, %3, '2', 0, 1, 0, 1, 1),
               (NULL, %1, %4, '3', 0, 1, 0, 1, 1)
    ", array(
          1 => array($recurring_payment_type_id, 'Positive'),
          2 => array(ts('Donor Managed'), 'String'),
          3 => array(ts('Avtale Giro'), 'String'),
          4 => array(ts('Printed Giro'), 'String')
       )
    );


}

function recurring_civicrm_uninstall() {
    CRM_Core_DAO::executeQuery("DROP TABLE civicrm_contribution_recur_offline");
}

function recurring_civicrm_xmlMenu(&$files) {
    $files[] = dirname(__FILE__) . "/Recurring/xml/Menu/RecurringPayment.xml";
}

/**
 * Implementation of hook_civicrm_pageRun
 */
function recurring_civicrm_pageRun(&$page) {

    if ($page->getVar('_name') == 'CRM_Contribute_Page_Tab') {

        $contact_id = CRM_Utils_Array::value('cid', $_GET, '');

        // modified - andyw@circle, 19/07/2013
        // show only recurring payments generated by the extension
        $query = "
            SELECT * FROM civicrm_contribution_recur ccr
            INNER JOIN civicrm_contribution_recur_offline ccro ON ccro.recur_id = ccr.id
            WHERE contact_id = %1
        ";

        $dao        = CRM_Core_DAO::executeQuery($query, array(1 => array($contact_id, 'String')));
        $recurArray = array();

        while ($dao->fetch())
            $recurArray[$dao->id] = array(
                'id'                      => $dao->id,
                'amount'                  => CRM_Utils_Money::format($dao->amount),
                'frequency_unit'          => $dao->frequency_unit,
                'frequency_interval'      => $dao->frequency_interval,
                'start_date'              => $dao->start_date,
                /*
                 * BOS1312355 add end_date to array
                 */
                'end_date'                => $dao->end_date,
                'next_sched_contribution' => $dao->next_sched_contribution
            );

        //for contribution tabular View
        $buildTabularView = CRM_Utils_Array::value('showtable', $_GET, false);
        $page->assign('buildTabularView', $buildTabularView);

        if ($buildTabularView)
            return;

        //$isAdmin was never defined anywhere in original code, andyw@circle
        //$page->assign('isAdmin', $isAdmin);
        $page->assign('recurArray', $recurArray);
        $page->assign('recurArrayCount', count($recurArray));
        
        if ($page->getVar('_action') == CRM_Core_Action::VIEW) {
          /*
           * BOS1312346 add earmaking and balanskonto
           */
          try {
            $contributionId = $page->getVar('_id');
          } catch (Exception $ex) {
              $contributionId = 0;
          }
          if (!empty($contributionId)) {
            $netsFields = _recurring_getNetsValues($contributionId);
            if (isset($netsFields['earmarking_label'])) {
              $page->assign('earMarkingLabel', 'Øremerking');
              $page->assign('earMarkingHtml', $netsFields['earmarking_label']);
            }
            if (isset($netsFields['balansekonto_label'])) {
              $page->assign('balanseKontoLabel', 'Balansekonto');
              $page->assign('balanseKontoHtml', $netsFields['balansekonto_label']);                    
            }
            /*
             * BOS1405148 add linked activity and donorgroup
             */
            $page->assign('linkedActivityLabel', ts('Linked Activity'));
            $page->assign('donorGroupLabel', ts('Donor Group'));
            $page->assign('linkedActivityHtml', recurring_get_linked_activity($contributionId));
            $page->assign('donorGroupHtml', recurring_get_donorgroup($contributionId));
          }           
        }
    }
    // endo BOS1312346 & BOS1405148
}
/**
 * Function to get linked donorgroup for contribution
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 17 Jun 2014
 * @param int $contributionId
 * @return string $donorgroup
 */
function recurring_get_donorgroup($contributionId) {
  $donorgroup = '';
  $query = 'SELECT grp.title FROM civicrm_contribution_donorgroup donor '
    . 'INNER JOIN civicrm_group grp ON donor.group_id = grp.id WHERE donor.contribution_id = %1';
  $dao = CRM_Core_DAO::executeQuery($query, array(1 => array($contributionId, 'Positive')));
  if ($dao->fetch()) {
    $donorgroup = $dao->title;
  }
  return $donorgroup;
}
/**
 * Function to get linked activity for contribution
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 17 Jun 2014
 * @param int $contributionId
 * @return string $activity
 */
function recurring_get_linked_activity($contributionId) {
  $activity = '';
  $query = 'SELECT act.subject, act.activity_date_time, ov.label AS activity_type '
    . 'FROM civicrm_contribution_activity link INNER JOIN civicrm_activity act ON '
    . 'link.activity_id = act.id LEFT JOIN civicrm_option_value ov ON '
    . 'act.activity_type_id = ov.value AND option_group_id = 2 WHERE link.contribution_id = %1';
  $dao = CRM_Core_DAO::executeQuery($query, array(1 => array($contributionId, 'Positive')));
  if ($dao->fetch()) {
    $activity = $dao->activity_type. ' - '.$dao->subject . ' - ' . 
      date('d-m-Y', strtotime($dao->activity_date_time));
  }
  return $activity;
}

/**
 * Implementation of hook civicrm_buildForm
 */
function recurring_civicrm_buildForm($formName, & $form) {
    /*
     * BOS1312346 add earmarking and balansekonto to Contribution form
     * for add and update mode (view is done in ContributionView.tpl)
     * 
     * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
     * @date 29 Mar 2014
     */
    if ($formName == 'CRM_Contribute_Form_Contribution') {
        if ($form->getVar('_action') == CRM_Core_Action::ADD || $form->getVar('_action') == CRM_Core_Action::UPDATE) {
            $earMarkings = array(0 => ts('- select -')) + _recurring_getOptionList('earmarking');
            $form->add('select', 'earmarking_id', ts('Øremerking'), $earMarkings, true);
            $balanseKontos = array(0 => ts('- select -')) + _recurring_getOptionList('balansekonto');
            $form->add('select', 'balansekonto_id', ts('Balansekonto'), $balanseKontos, true);
            if ($form->getVar('_action') == CRM_Core_Action::UPDATE) {
                $netsValues = _recurring_getNetsValues($form->getVar('_id'));
                if (isset($netsValues['earmarking_value'])) {
                    $defaults['earmarking_id'] = $netsValues['earmarking_value'];
                } else {
                    $defaults['earmarking_id'] = 330;
                }
                if (isset($netsValues['balansekonto_value'])) {
                    $defaults['balansekonto_id'] = $netsValues['balansekonto_value'];
                } else {
                    $defaults['balansekonto_id'] = 1920;
                }
            } else {
                $defaults['earmarking_id'] = 330;
                $form->setDefaults($defaults);
                $defaults['balansekonto_id'] = 1920;
            }
            if (isset($defaults) && !empty($defaults)) {
                $form->setDefaults($defaults);          
            }
        }
    }
}
/**
 * Implementation of hook civicrm_postProcess
 */
function recurring_civicrm_postProcess($formName, & $form) {
  /*
   * BOS1312346 update earmarking and balansekonto in nets_transactions
   * custom group in create and update mode
   * 
   * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
   * @date 30 Mar 2014
   */
  if ($formName == 'CRM_Contribute_Form_Contribution') {
    $netsGroupParams = array(
      'name'  =>  'nets_transactions',
      'return'=>  'table_name'
    );
    try {
      $netsGroupTable = civicrm_api3('CustomGroup', 'Getvalue', $netsGroupParams);
    } catch (CiviCRM_API3_Exception $e) {
      throw new CiviCRM_API3_Exception('Could not find custom group with name
        nets_transactions, something is wrong with your setup. Error message 
        from API CustomGroup Getvalue :'.$e->getMessage());
    }
    $earMarkingField = _recurring_getNetsField('earmarking');
    $balanseKontoField = _recurring_getNetsField('balansekonto');
    
    if ($form->getVar('_action') == CRM_Core_Action::UPDATE || $form->getVar('_action') == CRM_Core_Action::ADD) {
      if ($form->getVar('_action') == CRM_Core_Action::UPDATE) {
        $contributionId = $form->getVar('_id');
        $earmarkQuery = 'UPDATE '.$netsGroupTable.' SET '.$earMarkingField.' = %2, '
          .$balanseKontoField.' = %3 WHERE entity_id = %1';
      } else {
        $qryMax = 'SELECT MAX(entity_id) AS contributionId FROM '.$netsGroupTable;
        $daoMax = CRM_Core_DAO::executeQuery($qryMax);
        if ($daoMax->fetch()) {
          $contributionId = $daoMax->contributionId;
        }
        $earmarkQuery = 'REPLACE INTO '.$netsGroupTable.' (entity_id, '.$earMarkingField.', '.
          $balanseKontoField.') VALUES(%1, %2, %3)';
      }
      $submitValues = $form->getVar('_submitValues');
      /*
       * update fields in NETS TRANS record
       */
      $earmarkParams = array(
        1 => array($contributionId, 'Integer'),
        2 => array($submitValues['earmarking_id'], 'Integer'),
        3 => array($submitValues['balansekonto_id'], 'Integer')
      );
      CRM_Core_DAO::executeQuery($earmarkQuery, $earmarkParams);
    }
  }
}

function _recurring_getCRMVersion() {
    $crmversion = explode('.', ereg_replace('[^0-9\.]','', CRM_Utils_System::version()));
    return floatval($crmversion[0] . '.' . $crmversion[1]);
}

// retrieve the recurring payment types from civicrm_option_value
// (these are added when the extension is enabled)
function _recurring_getPaymentTypes() {
    $payment_types = array();
    $dao = CRM_Core_DAO::executeQuery("
        SELECT ov.label, ov.value FROM civicrm_option_value ov
        INNER JOIN civicrm_option_group og ON ov.option_group_id = og.id
        WHERE og.name = 'recurring_payment_type'
    ");
    while ($dao->fetch())
        $payment_types[$dao->value] = $dao->label;
    return $payment_types;
}

function _recurring_lookup_params_for_mailing($mailing_id) {

    $mailing = array();
    $dao = CRM_Core_DAO::executeQuery("
        SELECT * FROM civicrm_contribution_recur_mailing
        WHERE mailing_id = %1
    ", array(
          1 => array($mailing_id, 'Positive')
       )
    );
    if ($dao->fetch())
        foreach (array(
            'id', 'mailing_id', 'amount', 'frequency_unit', 'frequency_interval',
            'start_date', 'end_date', 'next_sched_contribution', 'maximum_amount',
            'payment_type_id', 'notification_for_bank'
        ) as $field)
            $mailing[$field] = $dao->$field;

    return $mailing ? $mailing : false;

}

function recurring_process_offline_recurring_payments() {
    ini_set('max_execution_time', 0); //run endless
		require_once 'api/api.php';
    require_once 'api/v3/utils.php';

    $config = &CRM_Core_Config::singleton();
    $debug  = false;

    $lookahead_day = strtotime('now +' . MAF_RECURRING_DAYS_LOOKAHEAD . ' day');

    // $day = date(
    //     "Ymd",
    //     mktime(0, 0, 0,
    //         date("m", $lookahead_day),
    //         date("d", $lookahead_day),
    //         date("Y", $lookahead_day)
    //     )
    // );

    $dayStart = date("Ymd", strtotime('now')) . "000000";
    $dayEnd   = date("Ymd", $lookahead_day) . "235959";

    // Select the recurring payment, where current date is equal to next scheduled date
    $dao = CRM_Core_DAO::executeQuery("
        SELECT * FROM civicrm_contribution_recur ccr
		INNER JOIN civicrm_contribution_recur_offline ccro ON ccro.recur_id = ccr.id
         WHERE (ccr.end_date IS NULL OR ccr.end_date > %1)
           AND ccr.next_sched_contribution >= %2
           AND ccr.next_sched_contribution <= %3
    ", array(
          1 => array(date('c', $lookahead_day), 'String'),
          2 => array($dayStart, 'String'),
          3 => array($dayEnd, 'String')
       )
    );

    $counter = 0;
    $errors  = 0;
    $output  = array();

    while($dao->fetch()) {
        $exist = false;
        $contact_id                 = $dao->contact_id;
        $hash                       = md5(uniqid(rand(), true));
        $total_amount               = (float) $dao->amount;
        $contribution_recur_id      = $dao->id;
        $contribution_type_id       = 1;
        $source                     = "Offline Recurring Contribution";
        $receive_date               = date("YmdHis", strtotime($dao->next_sched_contribution));
        $contribution_status_id     = 2;    // Set to pending, must complete manually
        $payment_instrument_id      = 3;
		
        $result = civicrm_api('contribution', 'getsingle',
            array(
                'version'                => 3,
                'contact_id'             => $contact_id,
                'receive_date'           => $receive_date,
                'total_amount'           => $total_amount,
                'payment_instrument_id'  => $payment_instrument_id,
                'contribution_type_id'   => $contribution_type_id,
                'contribution_recur_id'  => $contribution_recur_id,
            )
        );
        if (isset($result['is_error']) && $result['is_error']) {
			//contribution does not yet exist
			$result = civicrm_api('contribution', 'create',
				array(
					'version'                => 3,
					'contact_id'             => $contact_id,
					'receive_date'           => $receive_date,
					'total_amount'           => $total_amount,
					'payment_instrument_id'  => $payment_instrument_id,
					'trxn_id'                => $hash,
					'invoice_id'             => $hash,
					'source'                 => $source,
					'contribution_status_id' => $contribution_status_id,
					'contribution_type_id'   => $contribution_type_id,
					'contribution_recur_id'  => $contribution_recur_id,
					//'contribution_page_id'   => $entity_id
				)
			);
			if ($result['is_error']) {
				$output[] = $result['error_message'];
				++$errors;
				++$counter;
				continue;
			} else {
				$contribution = reset($result['values']);
				$contribution_id = $contribution['id'];
        
        /*
         * BOS1405148 add contribution/activity and contribution/donor group
         * based on contact and receive_date
         */
        $latestActivityId = ocr_get_latest_activity($contribution['contact_id']);
        ocr_create_contribution_activity($contribution['id'], $latestActivityId);
        $donorGroupId = ocr_get_contribution_donorgroup($contribution['id'], $contribution['receive_date'], $contribution['contact_id']);
        ocr_create_contribution_donorgroup($contribution['id'], $donorGroupId);
        
				$output[] = ts('Created contribution record for contact id %1', array(1 => $contact_id));
			}
		} else {
			//contribution already exist
			$output[] = ts('Contribution for contact id %1 already exist', array(1 => $contact_id));
			++$errors;
			$exist = true;
		}
		
        //$mem_end_date = $member_dao->end_date;
        $temp_date = strtotime($dao->next_sched_contribution);

        $next_collectionDate = strtotime ("+$dao->frequency_interval $dao->frequency_unit", $temp_date);
		$next_collectionDate_Y = date('Y', $next_collectionDate);
		$next_collectionDate_M = date('m', $next_collectionDate);
		$next_collectionDate_D = $dao->cycle_day;
		$next_collectionDate = strtotime($next_collectionDate_Y . '-'.$next_collectionDate_M.'-'.$next_collectionDate_D);
        $next_collectionDate = date('YmdHis', $next_collectionDate);

        CRM_Core_DAO::executeQuery("
            UPDATE civicrm_contribution_recur
               SET next_sched_contribution = %1
             WHERE id = %2
        ", array(
               1 => array($next_collectionDate, 'String'),
               2 => array($dao->id, 'Integer')
           )
        );

		if (!$exist) {
			$result = civicrm_api('activity', 'create',
				array(
					'version'             => 3,
					'activity_type_id'    => 6,
					'source_record_id'           => $contribution_id,
					'source_contact_id'   => $contact_id,
					'assignee_contact_id' => $contact_id,
					'subject'             => "Offline Recurring Contribution - " . $total_amount,
					'status_id'           => 2,
					'activity_date_time'  => date("YmdHis"),
				)
			);
			if ($result['is_error']) {
				$output[] = ts(
					'An error occurred while creating activity record for contact id %1: %2',
					array(
						1 => $contact_id,
						2 => $result['error_message']
					)
				);
				++$errors;
			} else {
				$output[] = ts('Created activity record for contact id %1', array(1 => $contact_id));

			}
		}
		++$counter;
	}
	
    // If errors ..
    if ($errors)
        return civicrm_api3_create_error(
            ts("Completed, but with %1 errors. %2 records processed.",
                array(
                    1 => $errors,
                    2 => $counter
                )
            ) . "<br />" . implode("<br />", $output)
        );

    // If no errors and records processed ..
    if ($counter)
        return civicrm_api3_create_success(
            ts(
                '%1 contribution record(s) were processed.',
                array(
                    1 => $counter
                )
            ) . "<br />" . implode("<br />", $output)
        );

    // No records processed
    return civicrm_api3_create_success(ts('No contribution records were processed.'));
}

// cron job converted from standalone cron script to job api call, andyw@circle
function civicrm_api3_job_process_offline_recurring_payments($params) {
	recurring_process_offline_recurring_payments();
}
/**
 * BOS1312346 Function to retrieve earmarkings from option group earmarking
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @return array
 */
function _recurring_getOptionList($listName) {
    /*
     * retrieve option group with name earmarking
     */
    $optionGroupParams = array(
        'name'    =>  $listName,
        'options' => array('limit' => 9999),
        'return'  =>  "id"
    );
    try {
        $optionGroupId = civicrm_api3('OptionGroup', 'Getvalue', $optionGroupParams);
    } catch (CiviCRM_API3_Exception $e) {
        throw new CiviCRM_API3_Exception('Could not find an option group with the name'.$listName.', message from API OptionGroup Getvalue : '.$e->getMessage);
    }
    $optionValuesParams = array(
        'option_group_id' => $optionGroupId,
        'options' => array('limit' => 99999));
    $apiOptionList = civicrm_api3('OptionValue', 'Get', $optionValuesParams);
    foreach($apiOptionList['values'] as $optionElement) {
        $optionList[$optionElement['value']] = $optionElement['label'];
    }
    return $optionList;
}
/**
 * BOS1312346 Function to retrieve the field name of a field in the
 * nets_transactions custom group
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 29 Mar 2014
 * @param string $fieldName
 * @return string $columnName
 * @throws CiviCRM_API3_Exception when nets_transactions not found
 */
function _recurring_getNetsField($fieldName) {
    $netsGroupParams = array(
        'name'  =>  'nets_transactions',
        'return'=>  'id'
    );
    try {
        $netsGroupId = civicrm_api3('CustomGroup', 'Getvalue', $netsGroupParams);
    } catch (CiviCRM_API3_Exception $e) {
        throw new CiviCRM_API3_Exception('Could not find custom group with name
            nets_transactions, something is wrong with your setup. Error message 
            from API CustomGroup Getvalue :'.$e->getMessage());
    }
    if (!empty($netsGroupId)) {
        $fieldParams = array(
            'custom_group_id'   =>  $netsGroupId,
            'name'              =>  $fieldName,
            'return'            =>  'column_name'
        );
        try {
            $columnName = civicrm_api3('CustomField', 'Getvalue', $fieldParams);
        } catch (CiviCRM_API3_Exception $e) {
            $columnName = "";
        }
        return $columnName;
    }
}
/**
 * BOS1312346 Function to retrieve option value for earmarking or balansekonto
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 29 Mar 2014
 * @param int $optionValue
 * @param string $optionName
 * @return string $optionLabel
 * @throws CiviCRM_API3_Exception when no option group or value found
 */
function _recurring_getNetsOptionValue($optionValue, $optionName) {
    if (empty($optionValue) || empty($optionName)) {
        return NULL;
    }
    $optionGroupParams = array(
        'name'  =>  $optionName,
        'return'=>  'id'
    );
    try {
        $optionGroupId = civicrm_api3('OptionGroup', 'Getvalue', $optionGroupParams);
    } catch (CiviCRM_API3_Exception $e) {
        throw new CiviCRM_API3_Exception('Could not find an option group for '.
            $optionName.' , error message from API OptionGroup Getvalue : '.$e->getMessage());
    }
    $optionValueParams = array(
        'option_group_id'   =>  $optionGroupId,
        'value'             =>  $optionValue,
        'return'            =>  'label'
    );
    try {
        $optionLabel = civicrm_api3('OptionValue', 'Getvalue', $optionValueParams);
    } catch (CiviCRM_API3_Exception $e) {
        throw new CiviCRM_API3_Exception('Could not find a valid label in option group  '.
            $optionName.' for value '.$optionValue.' , error message from API OptionValue Getvalue : '.$e->getMessage());
    }
    return $optionLabel;    
}
/**
 * BOS1312346 Function to retrieve values for earmarking and balanskonto for contribution
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 29 Mar 2014
 * @param int $contributionId
 * @return array $netsValues
 * @throws CiviCRM_API3_Exception when no custom group nets_transactions
 */
function _recurring_getNetsValues($contributionId) {
    $netsValues = array();
    if (empty($contributionId)) {
        return $netsValues;
    }
    $earMarkingField = _recurring_getNetsField("earmarking");
    $balanseKontoField = _recurring_getNetsField('balansekonto');
    $netsGroupParams = array(
        'name'  =>  'nets_transactions',
        'return'=>  'table_name'
    );
    try {
        $netsGroupTable = civicrm_api3('CustomGroup', 'Getvalue', $netsGroupParams);
    } catch (CiviCRM_API3_Exception $e) {
        throw new CiviCRM_API3_Exception('Could not find custom group with name
            nets_transactions, something is wrong with your setup. Error message 
            from API CustomGroup Getvalue :'.$e->getMessage());
    }
    $qryNets = 'SELECT '.$earMarkingField.', '.$balanseKontoField.' FROM '.$netsGroupTable.' WHERE entity_id = '.$contributionId;
    $daoNets = CRM_Core_DAO::executeQuery($qryNets);
    if ($daoNets->fetch()) {
        $netsValues['earmarking_label'] = _recurring_getNetsOptionValue($daoNets->$earMarkingField, 'earmarking');
        $netsValues['earmarking_value'] = $daoNets->$earMarkingField;
        $netsValues['balansekonto_label'] = _recurring_getNetsOptionValue($daoNets->$balanseKontoField, 'balansekonto');
        $netsValues['balansekonto_value'] = $daoNets->$balanseKontoField;
    }
    return $netsValues;
}
/**
 * BOS1312346 function to get financial type based on earmarking
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 31 Mar 2014
 * @param int $earMarking
 * @return int $finTypeId
 */
function _recurring_getFinType($earMarking) {
    $financialTypes = civicrm_api3('FinancialType', 'Get', array());
    foreach($financialTypes['values'] as $financialType) {
        switch($financialType['name']) {
            case "Flysponsor":
                $flyFinTypeId = $financialType['id'];
                break;
            case "Fuelsponsor":
                $fuelFinTypeId = $financialType['id'];
                break;
            case "Setesponsor":
                $seteFinTypeId = $financialType['id'];
        }
    }
    switch ($earMarking) {
        case 330:
            if (isset($seteFinTypeId)) {
                $finTypeId = $seteFinTypeId;
            } else {
                $finTypeId = 1;
            }
            break;
        case 331:
            if (isset($flyFinTypeId)) {
                $finTypeId = $flyFinTypeId;
            } else {
                $finTypeId = 1;
            }
            break;
        case 333:
            if (isset($fuelFinTypeId)) {
                $finTypeId = $fuelFinTypeId;
            } else {
                $finTypeId = 1;
            }
            break;
        default:
            $finTypeId = 1;
    }
    return $finTypeId;
}
/**
 * BOS1312346 Function to set the defaults for earmarking and balansekonto in 
 * nets transaction custom group. Defaults based on recurring contribution
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 31 Mar 2014
 * @param int $contributionId
 * @param int $recurId
 * @return void
 */
function _recurring_setNetsDefaults($contributionId, $recurId) {
    if (empty($contributionId) || empty($recurId)) {
        return;
    }
    /*
     * retrieve earmarking from recurring contribution
     */
    $daoEarmark = CRM_Core_DAO::executeQuery('SELECT earmarking_id FROM '
        .'civicrm_contribution_recur_offline WHERE recur_id = '.$recurId);
    if ($daoEarmark->fetch()) {
        $netsGroupParams = array(
            'name'  =>  'nets_transactions',
            'return'=>  'table_name'
        );
        try {
            $netsGroupTable = civicrm_api3('CustomGroup', 'Getvalue', $netsGroupParams);
        } catch (CiviCRM_API3_Exception $e) {
            throw new CiviCRM_API3_Exception('Could not find custom group with name
                nets_transactions, something is wrong with your setup. Error message 
                from API CustomGroup Getvalue :'.$e->getMessage());
        }
        $earMarkingField = _recurring_getNetsField('earmarking');
        $balanseKontoField = _recurring_getNetsField('balansekonto');
        $netsSql = 'REPLACE INTO '.$netsGroupTable.' (entity_id, '.$earMarkingField.', '.
            $balanseKontoField.') VALUES(%1, %2, %3)';
        $netsParams = array(
            1 => array($contributionId, 'Integer'),
            2 => array($daoEarmark->earmarking_id, 'Integer'),
            3 => array(1920, 'Integer')
        );
        CRM_Core_DAO::executeQuery($netsSql, $netsParams);
    }
    return;
}

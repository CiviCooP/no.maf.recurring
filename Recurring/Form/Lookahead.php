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
 */

/*
 * Contributions need to be created 6 weeks in advance so they
 * can be exported. This class manages the creation and updating
 * of linked contributions whenever the ContributionRecur form
 * is submitted.
 * andyw@circle, 06/10/2013
 */

class Recurring_Form_Lookahead {
    
    protected $custom_fields;
    protected $field_group_id;

    public function __construct() {
        
        if (!$this->custom_fields = @reset(CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'custom_fields')))
            CRM_Core_Error::fatal(ts(
                'Unable to retrieve custom fields for entity type contribution in %1 at line %2',
                array(
                    1 => __FILE__,
                    2 => __LINE__
                )
            ));
        
        $this->field_group_id = reset(array_keys(
            CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'custom_fields')
        ));

    }

    public function contributionCreate($params) {

        // loop to create all scheduled contributions between 
        // next_sched_contribution and recur end_date or 45 days time

        $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
        $error     = false;
        $date      = null;
		$end_date = null;
		
		if(!empty($params['start_date']))
            $start_date = CRM_Utils_Date::processDate($params['start_date']);
        if(!empty($params['end_date']))
            $end_date = CRM_Utils_Date::processDate($params['end_date']);
        if(!empty($params['next_sched_contribution']))    
            $next_sched_contribution = CRM_Utils_Date::processDate($params['next_sched_contribution']);

        for (
            
            // initializer
            $date    = new DateTime($next_sched_contribution),
            $counter = 0; 
            
            // condition
            (!empty($end_date) ? $date < new DateTime($end_date) : true) and 
            ($date < new DateTime('now +' . MAF_RECURRING_DAYS_LOOKAHEAD . ' day')); 
            
            // incrementer
            $date->modify(
                '+' . $params['frequency_interval'] .
                ' ' . $params['frequency_unit']
            ),
            $counter++

        ) {

            try {
                $createdContributiion = civicrm_api3('contribution', 'create', array(
                    /*
                     * BOS1312346 add financial type to contribution
                     * from recur (removed default 1)
                     */
                    'total_amount'           => $params['amount'],
                    'financial_type_id'      => $params['financial_type_id'], 
                    'contact_id'             => $params['cid'],
                    'receive_date'           => $date->format('c'),
                    'trxn_id'                => '',
                    'invoice_id'             => md5(uniqid(rand())),
                    'source'                 => ts('Offline Recurring Contribution'),
                    'contribution_status_id' => $status_id['Pending'],
                    'contribution_recur_id'  => $params['recur_id'] 
                ));
            } catch (CiviCRM_API3_Exception $e) {
                $error = $e->getMessage();
                break;
            }
            /*
             * BOS1312346 set default values for earmarking and balansekonto
             * in nets_transactions custom group
             */
            _recurring_setNetsDefaults($createdContributiion['id'], $params['recur_id']);

        }

        if ($error) {
            
            CRM_Core_Error::fatal(ts(
                'An error occurred creating initial contributions for ' . 
                'contribution_recur_id %1 in %2::%3: %4',
                array(
                    1 => $params['recur_id'],
                    2 => __CLASS__,
                    3 => __METHOD__,
                    4 => $error
                )
            ));

        } else {
            
            // Update next_sched_date on civicrm_contribution_recur
            CRM_Core_DAO::executeQuery("
                UPDATE civicrm_contribution_recur 
                   SET next_sched_contribution = %1 
                 WHERE id = %2
            ", array(
                   1 => array($date->format('c'), 'String'),
                   2 => array($params['recur_id'], 'Positive')
               )
            );

            ocr_set_message(ts(
                'Created %1 contribution(s) up until %2 days time, or the end date you specified (if sooner).',
                array(
                    1 => $counter,
                    2 => MAF_RECURRING_DAYS_LOOKAHEAD
                )
            ));

        }
    
    }

    public function contributionUpdate($params) {
        
        // first, delete all existing contributions which are part of 
        // this contribution recur and 'Pending' but not marked 'sent to bank'
        $custom_table = 'civicrm_value_nets_transactions_' . $this->field_group_id;
        $custom_field = 'sent_to_bank_' . $this->custom_fields['sent_to_bank'];

        $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
        $error     = false;
        $count     = 0;
		
		$end_date = null;
		
		if(!empty($params['start_date']))
            $start_date = CRM_Utils_Date::processDate($params['start_date']);
        if(!empty($params['end_date']))
            $end_date = CRM_Utils_Date::processDate($params['end_date']);
        if(!empty($params['next_sched_contribution']))    
            $next_sched_contribution = CRM_Utils_Date::processDate($params['next_sched_contribution']);

        $dao = CRM_Core_DAO::executeQuery("
                SELECT c.id FROM civicrm_contribution c
             LEFT JOIN $custom_table custom ON c.id = custom.entity_id
                 WHERE c.contribution_recur_id  = %1
                   AND c.contribution_status_id = %2
                   AND NULLIF (custom.$custom_field, 0) IS NULL
        ", array(
              1 => array($params['recur_id'], 'Positive'),
              2 => array($status_id['Pending'], 'Positive')
           )
        );

        while($dao->fetch())

            try {
                $result = civicrm_api3('contribution', 'delete', array(
                    'id' => $dao->id
                ));
                $count++;
            } catch (CiviCRM_API3_Exception $e) {
                $error = $e->getMessage();
                break;
            }
               
        if ($error)
            CRM_Core_Error::fatal(ts(
                'An error occurred creating initial contributions for ' . 
                'contribution_recur_id %1 in %2::%3: %4',
                array(
                    1 => $params['recur_id'],
                    2 => __CLASS__,
                    3 => __METHOD__,
                    4 => $error
                )
            ));
        else
            ocr_set_message(ts(
                'Deleted %1 contribution(s) not already sent to bank',
                array(1 => $count)
            )); 
        
        // Check the date of the last contribution not deleted, if one exists.
        // If it exists, and is greater than the next_scheduled_date submitted
        // by the form, use that + time interval as the next_scheduled_date
        // for creating new contributions

        if ($last_not_deleted = CRM_Core_DAO::singleValueQuery("
            SELECT receive_date FROM civicrm_contribution
             WHERE contribution_recur_id = %1
          ORDER BY receive_date DESC
             LIMIT 1
        ", array(
              1 => array($params['recur_id'], 'Positive')
           )
        )) {
            
            $current_next_scheduled   = new DateTime($last_not_deleted);
            $requested_next_scheduled = new DateTime($next_sched_contribution);
            
            $current_next_scheduled->modify(
                '+' . $params['frequency_interval'] . 
                ' ' . $params['frequency_unit']
            );
			$current_next_scheduled_Y = $current_next_scheduled->format('Y');
			$current_next_scheduled_M = $current_next_scheduled->format('m');
			$current_next_scheduled_D = $params['cycle_day'];
			
			$current_next_scheduled = new DateTime($current_next_scheduled_Y.'-'.$current_next_scheduled_M.'-'.$current_next_scheduled_D);

            if ($current_next_scheduled > $requested_next_scheduled)
                $params['next_sched_contribution'] = $current_next_scheduled->format('c');
        }

        // having modified next_sched_contribution (if necessary), pass $params
        // through to our creation function, which should re-create the 
        // necessary contributions
        $this->contributionCreate($params);
    
    }

}
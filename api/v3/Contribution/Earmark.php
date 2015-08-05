<?php

/**
 * Contribution.Earmark is a specific API for MAF Norge
 * Expected to run only once it will update the earmarking of any individual contributions that:
 * - have status Pending
 * - originate from a recurring contribution
 * - have a date in the future
 * - have an earmarking that is different from the earmarking of the recurring contribution
 * and it will update the earmarking to the earmarking of the recurring contribution
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_contribution_earmark($params) {
  $countUpdated = 0;
  $netsCustomGroup = getNetsTransactionTable();
  $earmarkingColumn = getEarmarkingColumn($netsCustomGroup['id']);

  $contributionQuery = 'SELECT contr.id AS contribution_id, recur.earmarking_id
FROM civicrm_contribution contr
LEFT JOIN '.$netsCustomGroup['table_name'].' nets ON contr.id = nets.entity_id
LEFT JOIN civicrm_contribution_recur_offline recur ON contr.contribution_recur_id = recur.recur_id
WHERE contr.contribution_recur_id IS NOT NULL
AND contr.contribution_status_id = %1
AND contr.receive_date >= NOW()
AND nets.'.$earmarkingColumn.' != recur.earmarking_id';

  $contributionParams = array(1 => array(2, 'Integer'));
  $dao = CRM_Core_DAO::executeQuery($contributionQuery, $contributionParams);
  while ($dao->fetch()) {
    $netsUpdate = 'UPDATE '.$netsCustomGroup['table_name'].' SET '.$earmarkingColumn.' = %1 WHERE entity_id = %2';
    $netsParams = array(
      1 => array($dao->earmarking_id, 'Integer'),
      2 => array($dao->contribution_id, 'Integer'));
    CRM_Core_DAO::executeQuery($netsUpdate, $netsParams);
    $countUpdated++;
  }

  $returnValues = array('is_error'=> 0, 'message' => 'Earmarking of, '.$countUpdated.' contributions updated.');
  return civicrm_api3_create_success($returnValues, $params, 'Contribution', 'Earmark');
}

/**
 * Function to retrieve the table name of the nets transaction custom table
 *
 * @return array
 * @throws Exception when error from API CustomGroup Getsingle
 */
function getNetsTransactionTable() {
  try {
    return civicrm_api3('CustomGroup', 'Getsingle', array('name' => 'nets_transactions'));
  } catch (CiviCRM_API3_Exception $e) {
    throw new Exception("Could not find a custom group with the name nets_transactions, unable to run contribution earmark!");
  }
}

/**
 * Function to get the earmarking column from the nets file
 *
 * @param int $customGroupId
 * @return string
 * @throws Exception when error from API CustomField Getvalue
 */
function getEarmarkingColumn($customGroupId) {
  $params = array(
    'custom_group_id' => $customGroupId,
    'name' => "earmarking",
    'return' => 'column_name');
  try {
    return civicrm_api3('CustomField', 'Getvalue', $params);
  } catch (CiviCRM_API3_Exception $e) {
    throw new Exception("Could not find a custom field with name earmarking and custom_group_id ".$customGroupId.", unable to run contribution earmark");
  }
}


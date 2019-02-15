<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/*
 +--------------------------------------------------------------------+
 | eWay Recurring Payment Processor Extension                         |
 +--------------------------------------------------------------------+
 | Licensed to CiviCRM under the Academic Free License version 3.0    |
 |                                                                    |
 | Originally written & contributed by Dolphin Software P/L - March   |
 | 2008                                                               |
 |                                                                    |
 | This is a combination of the original eWay payment processor, with |
 | customisations to handle recurring payments as well. Originally    |
 | started by Chris Ward at Community Builders in 2012.               |
 |                                                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | This code was initially based on the recent PayJunction module     |
 | contributed by Phase2 Technology, and then plundered bits from     |
 | the AuthorizeNet module contributed by Ideal Solution, and         |
 | referenced the eWAY code in Drupal 5.7's ecommerce-5.x-3.4 and     |
 | ecommerce-5.x-4.x-dev modules.                                     |
 |                                                                    |
 | Plus a bit of our own code of course - Peter Barwell               |
 | contact PB@DolphinSoftware.com.au if required.                     |
 |                                                                    |
 | NOTE: The eWAY gateway only allows a single currency per account   |
 |       (per eWAY CustomerID) ie you can only have one currency per  |
 |       added Payment Processor.                                     |
 |       The only way to add multi-currency is to code it so that a   |
 |       different CustomerID is used per currency.                   |
 |                                                                    |
 +--------------------------------------------------------------------+
*/

include_once 'au_com_agileware_ewayrecurring.class.php';

function _contribution_status_id($name) {
  return CRM_Utils_Array::key($name, \CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name'));
}

function ewayrecurring_civicrm_buildForm ($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if(!($page_id = $form->getVar('_id')))
      return;
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($page_id, 'Int')));
    if($default_cd) {
      $form->setDefaults(array('recur_cycleday' => $default_cd));
    }
  } elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {
    $paymentProcessor = $form->getVar('_paymentProcessorObj');
    if(($paymentProcessor instanceof au_com_agileware_ewayrecurring)){
      ($crid = $form->getVar('contributionRecurID')) || ($crid = $form->getVar('_crid'));
      if ($crid) {
        $sql = 'SELECT next_sched_contribution_date FROM civicrm_contribution_recur WHERE id = %1';
        $form->addDateTime('next_scheduled_date', ts('Next Scheduled Date'), FALSE, array('formatType' => 'activityDateTime'));
        if($default_nsd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($crid, 'Int')))){
          list($defaults['next_scheduled_date'],
            $defaults['next_scheduled_date_time']) = CRM_Utils_Date::setDateDefaults($default_nsd);
          $form->setDefaults($defaults);
        }
      }
    }
  } elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' && (($form->getVar('_paymentProcessorDAO') &&
      $form->getVar('_paymentProcessorDAO')->name == 'eWay_Recurring') || ($form->getVar('_ppDAO') && $form->getVar('_ppDAO')->name == 'eWay_Recurring')) &&
	    ($processor_id = $form->getVar('_id'))) {
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($processor_id, 'Int')));
    if($default_cd) {
      $form->setDefaults(array('recur_cycleday' => $default_cd));
    }
  }
}

function ewayrecurring_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount' ||
      $formName == 'CRM_Admin_Form_PaymentProcessor') {
    $cycle_day = CRM_Utils_Array::value('recur_cycleday', $fields);
    if($cycle_day == '')
      return;
    if (!CRM_Utils_Type::validate($cycle_day, 'Int', FALSE, ts('Cycle day')) || $cycle_day < 1 || $cycle_day > 31) {
      $errors['recur_cycleday'] = ts('Recurring Payment Date must be a number between 1 and 31');
    }

    if(empty(CRM_Utils_Array::value('user_name', $fields, ''))) {
      $errors['user_name'] = ts('API Key is a required field.');
    }

    if(empty(CRM_Utils_Array::value('password', $fields, ''))) {
      $errors['password'] = ts('API Password is a required field.');
    }

  } elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {

    $submitted_nsd = strtotime(CRM_Utils_Array::value('next_scheduled_date', $fields) . ' ' . CRM_Utils_Array::value('next_scheduled_date_time', $fields));

    ($crid = $form->getVar('contributionRecurID')) || ($crid = $form->getVar('_crid'));

    $sql = 'SELECT UNIX_TIMESTAMP(MAX(receive_date)) FROM civicrm_contribution WHERE contribution_recur_id = %1';
    $current_nsd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($crid, 'Int')));
    $form->setVar('_currentNSD', $current_nsd);

    if($submitted_nsd < $current_nsd)
      $errors['next_scheduled_date'] = ts('Cannot schedule next contribution date before latest received date');
    elseif ($submitted_nsd < time())
      $errors['next_scheduled_date'] = ts('Cannot schedule next contribution in the past');
  }
}

function ewayrecurring_civicrm_postProcess ($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if(!($page_id = $form->getVar('_id')))
      CRM_Core_Error::fatal("Attempt to process a contribution page form with no id");
    $cycle_day = $form->getSubmitValue('recur_cycleday');
    $is_recur = $form->getSubmitValue('is_recur');
    /* Do not continue if this is not a recurring payment */
    if (!$is_recur)
      return;
    if(!$cycle_day){
      $sql = 'DELETE FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int')));

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr
          INNER JOIN civicrm_contribution cc
                  ON cc.invoice_id            = ccr.invoice_id
           LEFT JOIN civicrm_ewayrecurring ceway
                  ON ccr.payment_processor_id = ceway.processor_id
                 SET ccr.cycle_day            = COALESCE(ceway.cycle_day, ccr.cycle_day)
               WHERE ccr.invoice_id           = cc.invoice_id
                 AND cc.contribution_page_id  = %1';

      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int')));
    }  else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_contribution_page_recur_cycle (page_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int'),
					     2 => array($cycle_day, 'Int')));

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr,
                     civicrm_contribution cc
                 SET ccr.cycle_day  = %2
               WHERE ccr.invoice_id = cc.invoice_id
                 AND cc.contribution_page_id = %1';

      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int'),
					     2 => array($cycle_day, 'Int')));
    }
  } elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' && (($form->getVar('_paymentProcessorDAO') &&
              $form->getVar('_paymentProcessorDAO')->name == 'eWay_Recurring') || ($form->getVar('_ppDAO') && $form->getVar('_ppDAO')->name == 'eWay_Recurring'))) {
    if(!($processor_id = $form->getVar('_id')))
      CRM_Core_Error::fatal("Attempt to configure a payment processor admin form with no id");

    $cycle_day = $form->getSubmitValue('recur_cycleday');

    if (!$cycle_day){
      $sql = 'DELETE FROM civicrm_ewayrecurring WHERE processor_id = %1';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int')));
      $cycle_day = 0;
    } else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_ewayrecurring (processor_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int'),
					     2 => array($cycle_day, 'Int')));
    }

    $sql = 'UPDATE civicrm_contribution_recur ccr
        INNER JOIN civicrm_contribution cc
                ON cc.invoice_id = ccr.invoice_id
         LEFT JOIN civicrm_ewayrecurring ceway
                ON ccr.payment_processor_id = ceway.processor_id
         LEFT JOIN civicrm_contribution_page_recur_cycle ccprc
                ON ccprc.page_id = cc.contribution_page_id
               SET ccr.cycle_day = %2
             WHERE ceway.processor_id = %1
               AND ccprc.cycle_day is NULL';

    CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int'),
					   2 => array($cycle_day, 'Int')));
  }
}

/*
 * Implements hook_civicrm_config()
 *
 * Include path for our overloaded templates */
function ewayrecurring_civicrm_config(&$config) {
  $template =& CRM_Core_Smarty::singleton();

  $ewayrecurringRoot =
    dirname(__FILE__) . DIRECTORY_SEPARATOR;

  $ewayrecurringDir = $ewayrecurringRoot . 'templates';

  if (is_array($template->template_dir)) {
    array_unshift($template->template_dir, $ewayrecurringDir);
  }
  else {
    $template->template_dir = array($ewayrecurringDir, $template->template_dir);
  }

  // also fix php include path
  $include_path = $ewayrecurringRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

function ewayrecurring_civicrm_managed(&$entities) {
   $entities[] = array(
     'module' => 'au.com.agileware.ewayrecurring',
     'name' => 'eWay_Recurring',
     'entity' => 'PaymentProcessorType',
     'params' => array(
       'version' => 3,
       'name' => 'eWay_Recurring',
       'title' => 'eWAY Recurring',
       'description' => 'Recurring payments payment processor for eWay',
       'class_name' => 'au.com.agileware.ewayrecurring',
       'user_name_label' => 'API Key',
       'password_label' => 'API Password',
       'billing_mode' => 'form',
       'is_recur' => '1',
       'payment_type' => '1',
     ),
   );
   $entities[] = array(
     'module' => 'au.com.agileware.ewayrecurring',
     'name' => 'eWay_Recurring_cron',
     'entity' => 'Job',
     'update' => 'never', // Ensure local changes are kept, eg. setting the job active
     'params' => array (
       'version' => 3,
       'run_frequency' => 'Always',
       'name' => 'eWAY Recurring Payments',
       'description' => 'Process pending and scheduled payments in the eWay_Recurring processor',
       'api_entity' => 'Job',
       'api_action' => 'run_payment_cron',
       'parameters' => "processor_name=eWay_Recurring",
       'is_active' => '0'
     ),
   );
   $entities[] = array(
      'module' => 'au.com.agileware.ewayrecurring',
      'name' => 'eWay_Transaction_Verification_cron',
      'entity' => 'Job',
      'update' => 'never', // Ensure local changes are kept, eg. setting the job active
      'params' => array (
        'version' => 3,
        'run_frequency' => 'Always',
        'name' => 'eWAY Transaction Verifications',
        'description' => 'Process pending transaction verifications in the eWay_Recurring processor',
        'api_entity' => 'EwayContributionTransactions',
        'api_action' => 'validate',
        'parameters' => "",
        'is_active' => '1'
      ),
   );
}

/**
 * Implements hook_civicrm_preProcess().
 * @param $formName
 * @param $form
 */
function ewayrecurring_civicrm_preProcess($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_Contribution_ThankYou') {
   $paymentProcessor = $form->getVar('_paymentProcessor');
   $paymentProcessor = $paymentProcessor['object'];

   if ($paymentProcessor instanceof au_com_agileware_ewayrecurring) {
     $invoiceID = $form->_params['invoiceID'];
     $contribution = civicrm_api3('Contribution', 'get', [
       'sequential' => 1,
       'invoice_id' => $invoiceID,
       'sequential' => TRUE,
       'return'     => array('contribution_page_id', 'contribution_recur_id', 'is_test'),
       'is_test'    => ($paymentProcessor->_mode == 'test') ? 1 : 0,
     ]);

     if (count($contribution['values']) > 0) {
       // Include eWay SDK.
       require_once 'vendor/autoload.php';

       $contribution = $contribution['values'][0];
       $eWayAccessCode = CRM_Utils_Request::retrieve('AccessCode', 'String', $form, FALSE, "");
       $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $form, FALSE, "");
       $paymentProcessorId = $paymentProcessor->getPaymentProcessor();
       $paymentProcessor->validateContribution($eWayAccessCode, $contribution, $qfKey, $paymentProcessorId);
     }
   }

  }
}

function ewayrecurring_civicrm_install() {
  // Do nothing here because the schema version can't be set during this hook.
}

function ewayrecurring_civicrm_postInstall() {
  CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', 6);
  // Update schemaVersion if added new version in upgrade process.
  // Also add database related CREATE queries.
  CRM_Core_DAO::executeQuery("CREATE TABLE `civicrm_contribution_page_recur_cycle` (`page_id` int(10) NOT NULL DEFAULT '0', `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY (`page_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  CRM_Core_DAO::executeQuery("CREATE TABLE `civicrm_ewayrecurring` (`processor_id` int(10) NOT NULL, `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY(`processor_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  CRM_Core_DAO::executeQuery("UPDATE `civicrm_payment_processor_type` SET billing_mode = 3 WHERE name = 'eWay_Recurring'");

  $files = glob(__DIR__ . '/sql/*_install.sql');
  if (is_array($files)) {
    foreach ($files as $file) {
      CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, $file);
    }
  }

}

function ewayrecurring_civicrm_uninstall() {
  $drops = array('DROP TABLE `civicrm_ewayrecurring`',
		 'DROP TABLE `civicrm_contribution_page_recur_cycle`');

  foreach($drops as $st) {
    CRM_Core_DAO::executeQuery($st, array());
  }

  $files = glob($this->extensionDir . '/sql/*_uninstall.sql');
  if (is_array($files)) {
    foreach ($files as $file) {
      CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, $file);
    }
  }
}

function ewayrecurring_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  $schemaVersion = intval(CRM_Core_BAO_Extension::getSchemaVersion('au.com.agileware.ewayrecurring'));
  $upgrades = array();

  if ($op == 'check') {
    if($schemaVersion < 6) {
      CRM_Core_Session::setStatus(ts('Version 2.0.0 of the eWAYRecurring extension changes the method of authentication with eWAY. To upgrade you will need to enter a new API Key and Password.  For more details see <a href="%1">the upgrade notes.</a>', [1 => 'https://github.com/agileware/au.com.agileware.ewayrecurring/blob/2.0.0/UPGRADE.md#200']), ts('eWAYRecurring Action Required'));
    }
    return array($schemaVersion < 6);
  } elseif ($op == 'enqueue') {
    if(NULL == $queue) {
      return CRM_Core_Error::fatal('au.com.agileware.ewayrecurring: No Queue supplied for upgrade');
    }
    if($schemaVersion < 3) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', array(
            3,
	    "CREATE TABLE `civicrm_contribution_page_recur_cycle` (`page_id` int(10) NOT NULL DEFAULT '0', `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY (`page_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
          ),
          'Install page_recur_cycle table'
        )
      );

    }
    if($schemaVersion < 4) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', array(
            4,
            "CREATE TABLE `civicrm_ewayrecurring` (`processor_id` int(10) NOT NULL, `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY(`processor_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
          ),
          'Install cycle_day table'
        )
      );
    }
    if ($schemaVersion < 5) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema_version', array(
          5,
        ),
          'Update schema version'
        )
      );
    }
    if ($schemaVersion < 6) {
      $queue->createItem(
          new CRM_Queue_Task('_ewayrecurring_upgrade_schema', array(
            6,
            "UPDATE civicrm_payment_processor_type SET user_name_label = 'API Key', password_label = 'API Password' WHERE name = 'eWay_Recurring'"
          ),
            'Perform Rapid API related changes'
          )
      );
    }
  }
}

function _ewayrecurring_upgrade_schema_version(CRM_Queue_TaskContext $ctx, $schema) {
    CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', $schema);
    return CRM_Queue_Task::TASK_SUCCESS;
}

function _ewayrecurring_upgrade_schema(CRM_Queue_TaskContext $ctx, $schema, $st, $params = array()) {
  $result = CRM_Core_DAO::executeQuery($st, $params);
  if (!is_a($result, 'DB_Error')) {
    CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', $schema);
    return CRM_Queue_Task::TASK_SUCCESS;
  } else {
    return CRM_Queue_Task::TASK_FAIL;
  }
}

/* Because we can't rely on PHP having anonymous functions. */
function _ewayrecurring_get_pp_id($processor) {
  return $processor['id'];
}

/**
 * Implements hook_civicrm_apiWrappers().
 */
function ewayrecurring_civicrm_entityTypes(&$entityTypes) {
  $entityTypes[] = array(
    'name'  => 'EwayContributionTransactions',
    'class' => 'CRM_eWAYRecurring_DAO_EwayContributionTransactions',
    'table' => 'civicrm_eway_contribution_transactions',
  );
}
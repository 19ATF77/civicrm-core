<?php

use Civi\Api4\OptionValue;
use Civi\Test\FormTrait;
use Civi\Test\FormWrapper;
use Civi\Test\Invasive;

/**
 *  Include dataProvider for tests
 * @group headless
 */
class CRM_Activity_Form_ActivityTest extends CiviUnitTestCase {

  use FormTrait;

  protected $assignee1;
  protected $assignee2;
  protected $target;
  protected $source;

  public function setUp():void {
    parent::setUp();
    $this->assignee1 = $this->individualCreate([
      'first_name' => 'test_assignee1',
      'last_name' => 'test_assignee1',
      'email' => 'test_assignee1@gmail.com',
    ]);
    $this->assignee2 = $this->individualCreate([
      'first_name' => 'test_assignee2',
      'last_name' => 'test_assignee2',
      'email' => 'testassignee2@gmail.com',
    ]);
    $this->target = $this->individualCreate();
    $this->source = $this->individualCreate();
  }

  public function tearDown(): void {
    if (!empty($this->ids['OptionValue'])) {
      OptionValue::delete(FALSE)->addWhere('id', 'IN', $this->ids['OptionValue'])->execute();
      unset($this->ids['OptionValue']);
    }
    parent::tearDown();
  }

  public function testActivityCreate(): void {
    Civi::settings()->set('activity_assignee_notification', TRUE);
    //Reset filter to none.
    Civi::settings()->set('do_not_notify_assignees_for', []);
    $mut = new CiviMailUtils($this, TRUE);
    $mut->clearMessages();

    $form = new CRM_Activity_Form_Activity();
    $activityTypeId = CRM_Core_PseudoConstant::getKey('CRM_Activity_DAO_Activity', 'activity_type_id', 'Meeting');
    $params = [
      'source_contact_id' => $this->source,
      'assignee_contact_id' => [$this->assignee1],
      'target_contact_id' => [$this->target],
      'followup_assignee_contact_id' => [],
      'activity_type_id' => $activityTypeId,
    ];

    Invasive::call([$form, 'processActivity'], [&$params]);

    $msg = $mut->getMostRecentEmail();
    $this->assertNotEmpty($msg);
    $mut->clearMessages();

    //Block Meeting notification.
    Civi::settings()->set('do_not_notify_assignees_for', [$activityTypeId]);
    $params['assignee_contact_id'] = [$this->assignee2];
    Invasive::call([$form, 'processActivity'], [&$params]);

    $msg = $mut->getMostRecentEmail();
    $this->assertEmpty($msg);
  }

  public function testActivityDelete(): void {
    // Set the parameters of the test.
    $numberOfSingleActivitiesToCreate = 3;
    $numberOfRepeatingActivitiesToCreate = 6;
    $singleActivityToDeleteOffset = 1;
    $mode1ActivityToDeleteOffset = 1;
    $mode2ActivityToDeleteOffset = 3;
    $mode3ActivityToDeleteOffset = 2;

    // Track the target contact's activities.
    $expectedActivityIds = array_keys(CRM_Activity_BAO_Activity::getActivities(['contact_id' => $this->target]));

    // Create non-repeating activities.
    $meetingActivityTypeId = CRM_Core_PseudoConstant::getKey('CRM_Activity_DAO_Activity', 'activity_type_id', 'Meeting');
    $singleActivityIds = [];
    for ($activityCount = 0; $activityCount < $numberOfSingleActivitiesToCreate; $activityCount++) {
      $activityParams = [
        'source_contact_id' => $this->source,
        'target_contact_id' => $this->target,
        'activity_type_id' => $meetingActivityTypeId,
        'activity_date_time' => date_create('+' . $activityCount . ' weeks')->format('YmdHis'),
      ];
      $singleActivityBao = CRM_Activity_BAO_Activity::create($activityParams);
      $singleActivityIds[] = $singleActivityBao->id;
    }
    $expectedActivityIds = array_merge($expectedActivityIds, $singleActivityIds);

    // Create an activity to be repeated.
    $activityParams = [
      'source_contact_id' => $this->source,
      'target_contact_id' => $this->target,
      'activity_type_id' => $meetingActivityTypeId,
      'activity_date_time' => date('YmdHis'),
    ];
    $repeatingActivityBao = CRM_Activity_BAO_Activity::create($activityParams);

    // Create the repeating activity's schedule.
    $actionScheduleParams = [
      'name' => 'repeat_civicrm_activity_' . $repeatingActivityBao->id,
      'used_for' => 'civicrm_activity',
      'entity_value' => $repeatingActivityBao->id,
      'start_action_date' => $repeatingActivityBao->activity_date_time,
      'repetition_frequency_unit' => 'week',
      'repetition_frequency_interval' => 1,
      'start_action_offset' => $numberOfRepeatingActivitiesToCreate - 1,
    ];
    $actionScheduleBao = CRM_Core_BAO_ActionSchedule::writeRecord($actionScheduleParams);

    // Create the activity's repeats.
    $recurringEntityBao = new CRM_Core_BAO_RecurringEntity();
    $recurringEntityBao->entity_table = 'civicrm_activity';
    $recurringEntityBao->entity_id = $repeatingActivityBao->id;
    $recurringEntityBao->dateColumns = ['activity_date_time'];
    $recurringEntityBao->linkedEntities = [
      [
        'table' => 'civicrm_activity_contact',
        'findCriteria' => ['activity_id' => $repeatingActivityBao->id],
        'linkedColumns' => ['activity_id'],
        'isRecurringEntityRecord' => FALSE,
      ],
    ];
    $recurringEntityBao->scheduleId = $actionScheduleBao->id;
    $newEntities = $recurringEntityBao->generate();
    $repeatingActivityIds = array_merge([$repeatingActivityBao->id], $newEntities['civicrm_activity']);
    $expectedActivityIds = array_merge($expectedActivityIds, $repeatingActivityIds);

    // Assert that the expected activities exist.
    $this->assertTargetActivityIds($expectedActivityIds);

    // Delete a non-repeating activity.
    $activityId = $singleActivityIds[$singleActivityToDeleteOffset];
    $this->deleteActivity($activityId);
    $expectedActivityIds = array_diff($expectedActivityIds, [$activityId]);
    $this->assertTargetActivityIds($expectedActivityIds);

    // Delete one activity from series (mode 1).
    $activityId = $repeatingActivityIds[$mode1ActivityToDeleteOffset];
    $this->deleteActivity($activityId, 1);
    $expectedActivityIds = array_diff($expectedActivityIds, [$activityId]);
    $this->assertTargetActivityIds($expectedActivityIds);

    // Delete from one activity until end of series (mode 2).
    $activityId = $repeatingActivityIds[$mode2ActivityToDeleteOffset];
    $this->deleteActivity($activityId, 2);
    $expectedActivityIds = array_diff($expectedActivityIds, array_slice($repeatingActivityIds, $mode2ActivityToDeleteOffset));
    $this->assertTargetActivityIds($expectedActivityIds);

    // Delete all activities in series (mode 3).
    $activityId = $repeatingActivityIds[$mode3ActivityToDeleteOffset];
    $this->deleteActivity($activityId, 3);
    $expectedActivityIds = array_diff($expectedActivityIds, $repeatingActivityIds);
    $this->assertTargetActivityIds($expectedActivityIds);
  }

  /**
   * Test deleting an activity that has an attachment.
   */
  public function testActivityDeleteWithAttachment(): void {
    $loggedInUser = $this->createLoggedInUser();
    // Create an activity
    $activity = $this->callAPISuccess('Activity', 'create', [
      'source_contact_id' => $loggedInUser,
      'activity_type_id' => 'Meeting',
      'subject' => 'test with attachment',
      'status_id' => 'Completed',
      'target_id' => $this->target,
    ]);
    $this->assertNotEmpty($activity['id']);

    // Add an attachment - this will also create it in the filesystem.
    $attachment = $this->callAPISuccess('Attachment', 'create', [
      'name' => 'abc.txt',
      'mime_type' => 'text/plain',
      'entity_id' => $activity['id'],
      'entity_table' => 'civicrm_activity',
      'content' => 'delete me',
    ]);
    $this->assertNotEmpty($attachment['id']);

    // Check the file is actually there
    $file_path = $attachment['values'][$attachment['id']]['path'];
    $this->assertTrue(file_exists($file_path));

    // Call our local helper function to use the form to delete
    $this->deleteActivity($activity['id']);

    // File should be gone from the filesystem
    $this->assertFalse(file_exists($file_path), "File is still in filesystem $file_path");

    // Shouldn't be an entry in civicrm_entity_file
    $query_params = [1 => [$activity['id'], 'Integer']];
    $entity_file_id = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_entity_file WHERE entity_table='civicrm_activity' AND entity_id = %1", $query_params);
    $this->assertEmpty($entity_file_id, 'Entry is still in civicrm_entity_file table.');

    // In this situation there also shouldn't be an entry in civicrm_file since
    // there's no other references to it.
    $query_params = [1 => [$attachment['id'], 'Integer']];
    $file_id = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_file WHERE id = %1", $query_params);
    $this->assertEmpty($file_id, 'Entry is still in civicrm_file table.');
  }

  /**
   * Asserts that the target contact has the expected activity IDs
   *
   * @param array $expectedActivityIds
   *   An array of the activity IDs that are expected to exist for the target contact
   */
  private function assertTargetActivityIds($expectedActivityIds) {
    $actualActivityIds = array_keys(CRM_Activity_BAO_Activity::getActivities(['contact_id' => $this->target]));
    $this->assertEquals(array_fill_keys($expectedActivityIds, NULL), array_fill_keys($actualActivityIds, NULL));
  }

  /**
   * Tests the form's deletion of activities, with optional mode for repeating activities
   *
   * @param int $activityId
   *   The ID of the activity to delete
   * @param int $mode
   *   1 - delete the specified activity
   *   2 - delete the specified activity and all following activities in the series
   *   3 - delete all activities in the series
   */
  private function deleteActivity($activityId, $mode = NULL) {
    // For repeating activities, set the recurring entity mode.
    if (!is_null($mode)) {
      $recurringEntityBao = new CRM_Core_BAO_RecurringEntity();
      $recurringEntityBao->entity_table = 'civicrm_activity';
      $recurringEntityBao->entity_id = $activityId;
      $recurringEntityBao->mode($mode);
    }

    // Use a form to delete the activity.
    $form = new CRM_Activity_Form_Activity();
    $form->_action = CRM_Core_Action::DELETE;
    $form->_activityId = $activityId;
    $form->postProcess();
  }

  /**
   * Test that the correct variables are assigned for the activity type.
   *
   * Sadly for historial reasons this means 'activityTypeName' is actually the label.
   *
   * @throws \CRM_Core_Exception
   */
  public function testActivityTypeNameIsReallyLabel(): void {
    // Let's make a new activity type that has a different name from its label just to be sure.
    $this->createTestEntity('OptionValue', [
      'option_group_id:name' => 'activity_type',
      'name' => 'wp1234',
      'label' => 'Water Plants',
      'is_active' => 1,
      'value' => 800,
      'is_default' => 0,
    ]);

    $form = $this->getTestForm('CRM_Activity_Form_Activity', [], ['atype' => 800, 'cid' => $this->source, 'action' => 'add']);
    $form->processForm(FormWrapper::BUILT);
    $form->checkTemplateVariable('activityTypeName', 'Water Plants');
    $form->checkTemplateVariable('activityTypeNameAndLabel', ['machineName' => 'wp1234', 'displayLabel' => 'Water Plants', 'id' => 800]);
  }

  /**
   * Test that the machineName and displayLabel are assigned correctly to the
   * smarty template.
   *
   * See also testActivityTypeNameIsReallyLabel()
   */
  public function testActivityTypeAssignment(): void {
    $form = new CRM_Activity_Form_Activity();

    $form->_currentlyViewedContactId = $this->source;

    // Let's make a new activity type that has a different name from its label just to be sure.
    $actParams = [
      'option_group_id' => 'activity_type',
      'name' => '47395hc',
      'label' => 'Hide Cookies',
      'is_active' => 1,
      'is_default' => 0,
    ];
    $result = $this->callAPISuccess('option_value', 'create', $actParams);

    $form->_activityTypeId = $result['values'][$result['id']]['value'];

    // Do the thing we want to test
    $form->assignActivityType();

    // Check the smarty template has the correct values assigned.
    $keyValuePair = $form->getTemplate()->get_template_vars('activityTypeNameAndLabel');
    $this->assertEquals('47395hc', $keyValuePair['machineName']);
    $this->assertEquals('Hide Cookies', $keyValuePair['displayLabel']);

    // cleanup
    $this->callAPISuccess('option_value', 'delete', ['id' => $result['id']]);
  }

  /**
   * Test that inbound email is still treated properly if you change the label.
   * I'm not crazy about the strategy used in this test but I can't see another
   * way to do it.
   *
   * @throws \CRM_Core_Exception
   */
  public function testInboundEmailDisplaysWithLineBreaks(): void {
    // Change label
    $inbound_email = $this->callAPISuccess('OptionValue', 'getsingle', [
      'option_group_id' => 'activity_type',
      'name' => 'Inbound Email',
    ]);
    $this->callAPISuccess('OptionValue', 'create', [
      'id' => $inbound_email['id'],
      'label' => 'Probably Spam',
    ]);

    // Fake an inbound email and store it

    $messageBody = <<<ENDBODY
-ALTERNATIVE ITEM 0-
Hi,

Wassup!?!?

Let's check if the output when viewing the form has legible line breaks in the output.

Thanks!

-ALTERNATIVE ITEM 1-

<div dir="ltr">Hi,<br></div>
<div dir="ltr"><br></div>
<div dir="ltr">Wassup!?!?<br></div>
<div dir="ltr"><br></div>
<div dir="ltr">Let&#39;s check if the output when viewing the form has legible line breaks in the output.<br></div>
<div dir="ltr"><br></div>
<div dir="ltr">Thanks!<br></div>
-ALTERNATIVE END-
ENDBODY;

    $activity = $this->activityCreate([
      'subject' => 'Important message read immediately!',
      'duration' => NULL,
      'location' => NULL,
      'details' => $messageBody,
      'status_id' => 'Completed',
      'activity_type_id' => 'Inbound Email',
      'source_contact_id' => $this->source,
      'assignee_contact_id' => NULL,
    ]);
    $_REQUEST = [
      'context' => 'standalone',
      'cid' => $this->source,
      'action' => 'view',
      'id' => $activity['id'],
      'atype' => $activity['values'][$activity['id']]['activity_type_id'],
    ];

    // Simulate viewing it from the form.
    $output = $this->getRenderedFormContents('CRM_Activity_Form_Activity');

    // This kind of suffers from the same problem as the old webtests. It's
    // a bit brittle and tied to the UI.
    $this->assertStringContainsString("Hi,<br />\n<br />\nWassup!?!?<br />\n<br />\nLet's check if the output when viewing the form has legible line breaks in the output.<br />\n<br />\nThanks!", $output);

    // Put label back
    $this->callAPISuccess('OptionValue', 'create', [
      'id' => $inbound_email['id'],
      'label' => $inbound_email['label'],
    ]);
  }

}

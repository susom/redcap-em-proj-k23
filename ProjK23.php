<?php
namespace Stanford\ProjK23;

use ExternalModules\ExternalModules;
use \REDCap;
use \Project;
use DateTime;
use DateInterval;

require_once 'emLoggerTrait.php';

class ProjK23 extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    public $survey_record;
    public $main_pk;
    public $main_record;

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1 ) {

        /**
         *  To triggered updates:
         *      1. On initial save, auto-create the RSP Participant Info forms for the baseline survey and followup surveys
         *      2. If REDO baseline is selected, create a third Participant Info for the redo of the baseline surveys
         *
         */
        $baseline_config     = $this->getProjectSetting('baseline-portal-config-name');
        $followup_config     = $this->getProjectSetting('followup-portal-config-name');

        $target_form          = $this->getProjectSetting('triggering-instrument');
        $config_event         = $this->getProjectSetting('main-config-event-name');
        $config_field         = $this->getProjectSetting('config-field');
        $target_instrument    = $this->getProjectSetting('target-instrument');

        //for baseline dailies count
        $baseline_final_form         = $this->getProjectSetting('baseline-dailies-form-name');
        $baseline_event              = $this->getProjectSetting('baseline-dailies-event-name');
        $baseline_count_field        = $this->getProjectSetting('baseline-dailies-count-field');
        $baseline_gc_date_field      = $this->getProjectSetting('baseline-dailies-gc-date-field');

        //for followup dailies count
        $followup_final_form         = $this->getProjectSetting('followup-dailies-form-name');
        $followup_event              = $this->getProjectSetting('followup-dailies-event-name');
        $followup_count_field        = $this->getProjectSetting('followup-dailies-count-field');
        $followup_gc_date_field      = $this->getProjectSetting('followup-dailies-gc-date-field');

        //separate portal invite for followup portal
        $followup_invite_date_field      = $this->getProjectSetting('followup-dailies-invite-date-field');


        $this->emDebug("baseline config is $baseline_config and followup_config is $follwoup_config");
        $this->emDebug("SAve Record / record=$record / instrument=$instrument / EVENTID = $event_id");
        $this->emDebug("Target Record / instrument=$baseline_final_form / EVENTID = $baseline_event");

        //if either are empty, bail
        if (empty($baseline_config) || empty($followup_config)) {
            $this->emError("baseline config ID or followup config ID is not set.  Unable to set up the hooks for this project ($project_id).");
            return;
            //$this->exitAfterHook();
        }

        //Participant_info was saved so check if configs need to be setup
        if ($instrument == $target_form) {
            $auto_create_field    = $this->getProjectSetting('auto-create-field');



            //check that the target forms haven't already been created
            $params = array(
                    'return_format' => 'json',
                    'records' => $record,
                    'fields' => array($config_field, $auto_create_field),
                    'events' => $config_event
//                'redcap_repeat_instrument' => $instrument,       //this doesn't restrict
//                'redcap_repeat_instance'   => $repeat_instance   //this doesn't seem to do anything!
            );

            $q = REDCap::getData($params);
            $results = json_decode($q, true);

            //$this->emDebug($params, $results, $config_field); //exit;
            //$this->emDebug("CREATE FIELD ? : ".$results[0][$auto_create_field.'___1'] );

            $baseline_set = array_search($baseline_config, array_column($results, 'rsp_prt_config_id'));
            $this->emDebug("RECID: " . $record . " KEY: " . $baseline_set . " KEY IS NULL: " . empty($baseline_set) . " : " . isset($baseline_set));

            $fup_set = array_search($followup_config, array_column($results, 'rsp_prt_config_id'));
            $this->emDebug("RECID: " . $record . " KEY: " . $fup_set . " KEY IS NULL: " . empty($fup_set) . " : " . isset($fup_set));

            //get the currrent data
            $entered_data = $this->getEnteredData($record, $event_id);
            //$this->emDebug($entered_data);


            //handle BASELINE
            $bl_date_field      = $this->getProjectSetting('baseline-date-field');
            //check if baseline dailies rsp form is already set
            if (empty($baseline_set)) {
                //check if checkbox to autocreate is set
                if ($results[0][$auto_create_field.'___1'] == 1) {

                    //creating a new instance
                    $bl_repeat_instance = $this->getNextRepeatingInstanceID($record, $target_instrument,$config_event);
                    $this->emDebug("NEXT Repeating Instance ID for  ".$record ." IS ".$bl_repeat_instance);
                    $this->updateRSPParticipantInfoForm('baseline_dailies', $record, $event_id, $bl_date_field,$bl_repeat_instance,$entered_data);


                }
            } else {
                //get the repeat instance for the baseline config
                $bl_repeat_instance = $this->getInstanceIDForConfigID($record, $event_id, $baseline_config);  //should be 1
                $this->updateRSPParticipantInfoForm($baseline_config, $record, $event_id, $bl_date_field,$bl_repeat_instance,$entered_data);
            }


            //calculate the Survey end date for baseline
            $this->updateSurveyEndDate($record,$entered_data[$bl_date_field], 14,  $baseline_gc_date_field, $config_event);

            //only checking that the config id is set. should i also be checking that a hash was successfully created?


            //handle FOLLOWUP
            $fup_date_field      = $this->getProjectSetting('class-date-field');
            //check if followup_dailies are set
            if (empty($fup_set)) {
                if ($results[0][$auto_create_field.'___1'] == 1) {
                    $fup_repeat_instance = $this->getNextRepeatingInstanceID($record, $target_instrument,$config_event);
                    $this->emDebug("NEXT Repeating Instance ID for  $record  IS ".$fup_repeat_instance);
                    $this->updateRSPParticipantInfoForm($followup_config, $record, $event_id, $fup_date_field,$fup_repeat_instance,$entered_data);


                    //update the end date for gift card check

                }

            } else {
                //get the repeat instance of the RSp_participant_info form where the config_id = $followup_config ('followup_dailies')
                $fup_repeat_instance = $this->getInstanceIDForConfigID($record, $event_id, $followup_config); //should be 2
                $this->updateRSPParticipantInfoForm($followup_config, $record, $event_id, $fup_date_field,$fup_repeat_instance,$entered_data);
            }

            //calculate the Survey end date for followup
            $this->updateSurveyEndDate($record, $entered_data[$fup_date_field], 44, $followup_gc_date_field, $config_event);

            //calculate the portal invite date for followup
            $this->updateSurveyEndDate($record, $entered_data[$fup_date_field], 21, $followup_invite_date_field, $config_event);

        }

        //  2. Calculate counts for baseline dailies
        if (($event_id == $baseline_event ) && ($instrument == $baseline_final_form)) {
            $this->emDebug("Checking the baseline");
            $this->checkSurveyCounts($record, $baseline_event, $baseline_final_form,$baseline_count_field, $config_event);


        }


        // 3. Calculate counts for followup dailies
        if (($event_id == $followup_event ) && ($instrument == $followup_final_form)) {
            $this->checkSurveyCounts($record, $followup_event, $followup_final_form,$followup_count_field, $config_event);
        }


    }

    /**
     * Given the $config_id ('baseline_dailies', followup_dailies, etc) return the redcap_repeat_instance of the
     * rsp_participant_info for this form
     * @param $record
     * @param $event_id
     * @param $config_id
     * @return mixed
     */
    private function getInstanceIDForConfigID($record, $event_id, $config_id) {
        //hardcode portal config id
        $config_id_field = 'rsp_prt_config_id';
        $event_name = REDCap::getEventNames(true, false, $event_id);

        $filter = "[" . $event_name . "][" . $config_id_field . "] = '$config_id'";

        $params = array(
            'return_format'    => 'json',
            'records'          => $record,
            'events'           => $event_id,
            'fields'           => array(REDCap::getRecordIdField(), 'redcap_repeat_instance', $config_id_field),
            'filterLogic'      => $filter
        );

        $q = REDCap::getData($params);

        $records = json_decode($q, true);

        //get the key of array where config_id is our search
        $lookup = array_filter(array_column($records, $config_id_field, $config_id));
        return $records[key($lookup)]['redcap_repeat_instance'];


    }


    function checkSurveyCounts($record, $event, $final_form, $target_count_field, $target_event) {
        $baseline_event_name = REDCap::getEventNames(true, false, $event);

        $filter = "[" . $baseline_event_name . "][" . $final_form.'_complete' . "] = '2'";

        $params = array(
            'return_format'    => 'json',
            'records'          => $record,
            'events'           => $event,
            'fields'           => array(REDCap::getRecordIdField(),$final_form.'_complete' ),
            'filterLogic'      => $filter
        );

        $q = REDCap::getData($params);


        $records = json_decode($q, true);
        //$this->emDebug( $params,$records, count($records));

        //save the counts
        $data = array(
            REDCap::getRecordIdField() => $record,
            'redcap_event_name'        => REDCap::getEventNames(true, false,$target_event),
            $target_count_field        => count($records)
        );

        REDCap::saveData($data);
        $response = REDCap::saveData('json', json_encode(array($data)));

        if ($response['errors']) {
            $msg = "Error while trying to update field: $target_count_field";
            $this->emError($response['errors'], $data, $msg);
        } else {
            $this->emDebug("Successfully saved data to $target_count_field.");
        }

    }

    /**
     * There is a request to postpone sending of portal invite for secondary followup surveys to a later date.
     * There is now a followup-dailies-invite-date-field which records the date of the delayed portal invite
     *
     * This is the cron method initiating the delayed portal invite check
     *
     */
    public function startInviteCron() {
        $this->emDebug("Starting Invite Cron");

        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        //get the noAuth api endpoint for Cron job.
        $url = $this->getUrl('startInviteCron.php', true, true);

        $current_hour = date('H');

        while ($proj = db_fetch_assoc($enabled)) {
            $pid = $proj['project_id'];
            $this->emDebug("STARTING PID: ".$pid);

            //For each project, see if the invite has been enabled
            $enabled_invite = $this->getProjectSetting('portal-invite-send', $pid);


            if ($enabled_invite == true) {

                //check if it is the right time to send
                $invite_time = $this->getProjectSetting('portal-invite-time', $pid);

                //$this->emDebug("checking $invite_time");

                //if  the right hour, start the check
                if ($invite_time == $current_hour) {

                    $this->emDebug("Starting $invite_time");
                    $this_url = $url . '&pid=' . $pid;
                    $resp = http_get($this_url);
                }

            }

        }

    }

    /**
     * There is a request to postpone sending of portal invite for secondary followup surveys to a later date.
     * There is now a followup-dailies-invite-date-field which records the date of the delayed portal invite
     *
     * This is triggered from the cron method initiating the delayed portal invite check
     *
     * @throws \Exception
     */
    public function sendPortalInvite() {
        $email_field                     = $this->getProjectSetting('email-field');
        $fup_url_field  = $this->getProjectSetting('portal-url-field');
        $event      = $this->getProjectSetting('main-config-event-name');
        $event_name = REDCap::getEventNames(true, false, $event);

        //go through all the records where everything is not disabled
           //portal , email_disabled, email not blank
          //check that the email hasn't already been sent
          //check that it's been 3 weeks after the class_date
        $candidates = $this->getFUPPortalInviteCandidates();
        $today = new DateTime();

        foreach ($candidates as $candidate) {
            //send email
            $this->emDebug("Sending portal invite email to ". $candidate[$email_field]);

            //get the portal url
            $params = array(
                'return_format' => 'json',
                'records'       => $candidate[REDCap::getRecordIdField()],
                'fields'        => array(
                    REDCap::getRecordIdField(),
                    $fup_url_field,
                    'rsp_prt_config_id'
                ),
                'events'        => $event,
                'filterLogic'   => "([{$event_name}][rsp_prt_config_id]='followup_dailies')"
            );

            $q = REDCap::getData($params);
            $fup_result = json_decode($q, true);

            //$this->emDebug($fup_result, $params); //exit;

            $send_result = $this->sendEmail($candidate[REDCap::getRecordIdField()], $candidate[$email_field], $fup_result[0][$fup_url_field]);

            if ($send_result == true) {
                $ts_field = $this->getProjectSetting('followup-dailies-invite-sent-field');

                //save timestamp
                $data = array(
                    REDCap::getRecordIdField() => $candidate[REDCap::getRecordIdField()],
                    'redcap_event_name'        => $event_name,
                    $ts_field              => $today->format('Y-m-d H:i:s')

                );

                $this->emDebug('Saved to  $ts_field: '.$today->format('Y-m-d H:i:s'), $data);
                REDCap::saveData($data);
                $response = REDCap::saveData('json', json_encode(array($data)));
            }

        }

    }

    /**
     * Request to postpone sending of portal invite for secondary followup surveys to a later date.
     * There is now a followup-dailies-invite-date-field which records the date of the delayed portal invite
     *
     * Do a REDCap filter search on the project where
     *    1. config-id field matches the config-id in the subsetting for this config
     *    2. emails has not been disabled for this participant and the email field is not empty
     *    3. phone has not been disabled for this participant and the phone field is not empty
     *
     * @return bool|mixed
     */
    public function getFUPPortalInviteCandidates( ) {
        //get config settings

        $invite_sent_field      = $this->getProjectSetting('followup-dailies-invite-sent-field');
        $target_date_field = $this->getProjectSetting('followup-dailies-invite-date-field');
        $email_field  = $this->getProjectSetting('email-field');
        $email_disabled_field  = $this->getProjectSetting('disable-email-field');
        $fup_disabled_field  = $this->getProjectSetting('stop-fup-field');
        $fup_url_field  = $this->getProjectSetting('portal-url-field');

        $event = $this->getProjectSetting('main-config-event-name');
        $event_name = REDCap::getEventNames(true, false, $event);

        $today = new DateTime();
        $today_str = $today->format('Y-m-d');


        //1. get all the records where the send date is today
        //2. not already sent
        //3. email not disabled
        //3. portal not disabled
        //4. email not blank

        $filter = "(".
            "(datediff( 'today',[{$event_name}][{$target_date_field}], 'd', true) =0) AND ".   //send date is today
            "([{$event_name}][{$invite_sent_field}]='') AND ".       //not already sent
            "([{$event_name}][{$email_field}]<>'') AND ".                 //email not blank
            "([{$event_name}][{$email_disabled_field}(1)]<>'1') AND ".         //email not disabled
            "([{$event_name}][{$fup_disabled_field}(1)]<>'1')".         //fup not disabled
            ")";

        $this->emDebug($filter);
        $params = array(
            'return_format' => 'json',
            'fields' => array(
                REDCap::getRecordIdField(),
                $target_date_field,
                $invite_sent_field,
                $email_disabled_field,
                $fup_disabled_field,
                $fup_url_field,
                $email_field),
            'events' => $event,
            'filterLogic'  => $filter
        );

        $q = REDCap::getData($params);
        $result = json_decode($q, true);



        // the filter returns an empty array for every found array.
        //iterate over the returned result and delete the ones where redcap_repeat_instance is NOT blank since this is not repeating
        $candidate = array();
        foreach ($result as $k => $v) {
            if (!empty($v['redcap_repeat_instance'])) {
                continue;
            }
            $candidate[] = $v;
        }

        //$this->emDebug($params, $result, $candidate); //exit;
        return $candidate;

    }

    /**
     * Retrieve the fields entered in the K23 Participant Setup page to be copied over to the RSP form
     *
     * @param $record
     * @param $event
     * @return array|bool
     */
    function getEnteredData($record, $event) {
        $enrolled_form_field        = $this->getProjectSetting('enrolled-field');
        $baseline_date_field        = $this->getProjectSetting('baseline-date-field');
        $class_date_field        = $this->getProjectSetting('class-date-field');
        $email_field        = $this->getProjectSetting('email-field');
        $phone_field        = $this->getProjectSetting('phone-field');
        $disable_email_field = $this->getProjectSetting('disable-email-field');
        $disable_sms_field   = $this->getProjectSetting('disable-sms-field');
        $stop_baseline_field = $this->getProjectSetting('stop-baseline-field');
        $stop_followup_field = $this->getProjectSetting('stop-fup-field');

        $params = array(
            'return_format'       => 'json',
            'records'             => $record,
            'fields'              => array($enrolled_form_field,$baseline_date_field, $class_date_field,$email_field,$phone_field,
                $disable_email_field,$disable_sms_field,$stop_baseline_field,$stop_followup_field),
            'events'              => $event
//                'redcap_repeat_instrument' => $instrument,       //this doesn't restrict
//                'redcap_repeat_instance'   => $repeat_instance   //this doesn't seem to do anything!
            );

            $q = REDCap::getData($params);
            $results = json_decode($q, true);

        //$this->emDebug($params,$results, count(array_filter($results[0])),$results[0][$email_field], $results[0][$phone_field],
//            empty($results[0][$email_field]),empty($results[0][$phone_field]));

        //this check is no longer needed as it uses the checkbox
//        if (count(array_filter($results[0])) < 4) {
//                return false;
//        } else {
//
//            if (empty($results[0][$email_field]) and (empty($results[0][$phone_field]))) {
//                return false;
//            }
//
//        }
        return $results[0];

    }

    function updateRSPParticipantInfoForm($config_id, $record, $event_id, $start_date_field, $repeat_instance,$entered_data)
    {
        $config_event = $this->getProjectSetting('main-config-event-name');
        $target_instrument = $this->getProjectSetting('target-instrument');

        if (!isset($target_instrument)) {
            $this->emError("Target instrument is not set in the EM config. Data will not be transferred. Set config for target-instrument.");
            return false;
        }


        $data_array = array(
            'rsp_prt_start_date' => $entered_data[$start_date_field],
            'rsp_prt_config_id' => $config_id, //i.e. 'baseline_daililes' or 'followup_dailies'
            'rsp_prt_portal_phone' => $entered_data[$this->getProjectSetting('phone-field')],
            'rsp_prt_portal_email' => $entered_data[$this->getProjectSetting('email-field')],
            'rsp_prt_portal_phone' => $entered_data[$this->getProjectSetting('phone-field')]
        );

        //handle the checkboxes
        $data_array['rsp_prt_disable_email___1']  = $entered_data[$this->getProjectSetting('disable-email-field'). '___1'];
        $data_array['rsp_prt_disable_sms___1']    = $entered_data[$this->getProjectSetting('disable-sms-field'). '___1'];

        //unset the checkbox so we stop getting the warning that the value is set
        $data_array['k23_auto_setup_rsp___1']     = 0;


        //add the baseline vs followup specific disable checkbox
        switch ($config_id) {
            case 'baseline_dailies':
                $data_array['rsp_prt_disable_portal___1'] = $entered_data[$this->getProjectSetting('stop-baseline-field') . '___1'];
                break;
            case 'followup_dailies':
                $data_array['rsp_prt_disable_portal___1'] = $entered_data[$this->getProjectSetting('stop-fup-field') . '___1'];
                break;
        }

        //$this->emDebug($data_array, $entered_data, $this->getProjectSetting('stop-baseline-field'), $entered_data[$this->getProjectSetting('stop-baseline-field') . '___1'], $entered_data[$this->getProjectSetting('stop-fup-field') . '___1']);

        //save the data
        $this->saveRSPParticipantInfo($record, $config_event, $data_array, $target_instrument,$repeat_instance);

        //trigger the hash creation and sending of the email by triggering the redcap_save_record hook on  the rsp_participant_info form
        // \Hooks::call('redcap_save_record', array($child_pid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
        \Hooks::call('redcap_save_record', array($this->getProjectId(), $record, $target_instrument, $config_event, null, null, null, $repeat_instance));
    }

    function updateSurveyEndDate($record, $start_date, $offset, $target_field, $target_event) {


        if (!empty($start_date)) {
            //do some date addition to get the end date
            $end_date = new DateTime($start_date);
            $end_date->add(new DateInterval('P'.$offset.'D'));



            //save the date
            $data = array(
                REDCap::getRecordIdField() => $record,
                'redcap_event_name'        => REDCap::getEventNames(true, false,$target_event),
                $target_field              => $end_date->format('Y-m-d')

            );

            //$this->emDebug('Started with $start_date.sveing to $target_field: '.$end_date->format('Y-m-d'), $data);
            REDCap::saveData($data);
            $response = REDCap::saveData('json', json_encode(array($data)));

            if ($response['errors']) {
                $msg = "Error while trying to add angio form.";
                $this->emError($response['errors'], $data, $msg);
            } else {
                $this->emDebug("Successfully saved data to $target_field.");
            }
        }
    }

    function saveRSPParticipantInfo($record_id, $event_id, $data_array, $instrument,$repeat_instance) {
        //$instrument = 'rsp_participant_info';

        $params = array(
            REDCap::getRecordIdField()                => $record_id,
            //'events'                                  => $event_id,
            'redcap_repeat_instrument'                => $instrument,
            'redcap_repeat_instance'                  => $repeat_instance
        );

        $data = array_merge($params, $data_array);

        //$this->emDebug($params, $data);

        //$new_instance[$record_id]['repeat_instances'][$event_id][$instrument][$next_instance_id] = $data_array;
        //$result = REDCap::saveData($this->getProjectId(), 'array', $new_instance);
        //$this->emDebug($result, $new_instance);

        $result = REDCap::saveData('json', json_encode(array($data)));
        if ($result['errors']) {
            $this->emError($result['errors'], $params);
            $msg[] = "Error while trying to add $instrument form.";
            //return false;
        } else {
            $msg[] = "Successfully saved data to $instrument.";
        }

    }

     /**
     * Return the next instance id for this survey instrument
     *
     * Using the getDAta with return_format = 'array'
     * the returned nested array :
     *  $record
     *    'repeat_instances'
     *       $event
     *          $instrument
     *
     *
     * @return int|mixed
     */
    public function getNextRepeatingInstanceID($record, $instrument, $event) {

        $this->emDebug($record . " instrument: ".  $instrument. " event: ".$event);
        //getData for all surveys for this reocrd
        //get the survey for this day_number and survey_data
        //TODO: return_format of 'array' returns nothing if using repeatint events???
        //$get_data = array('redcap_repeat_instance');
        $params = array(
            'return_format'       => 'array',
            'fields'              => array('redcap_repeat_instance','rsp_prt_start_date',$instrument."_complete"),
            'records'             => $record
            //'events'              => $this->portalConfig->surveyEventID
        );
        $q = REDCap::getData($params);
        //$results = json_decode($q, true);

        $instances = $q[$record]['repeat_instances'][$event][$instrument];
        //$this->emDebug($params, $q, $instances);


        ///this one is for standard using array
        $max_id = max(array_keys($instances));

        //this one is for longitudinal using json
        //$max_id = max(array_column($results, 'redcap_repeat_instance'));

        return $max_id + 1;
    }

    /**
     *
     *
     * @param $project_id
     * @param $url_field
     * @param $event
     * @return string
     */
    public function generateUniquePersonalHash($hash_field, $event) {
        //$url_field   = $this->getProjectSetting('personal-url-fields');  // won't work with sub_settings

        $i = 0;
        do {
            $new_hash = generateRandomHash(8, false, TRUE, false);

            $this->emDebug("NEW HASH ($i):" .$new_hash);
            $params = array(
                'return_format' => 'array',
                'fields' => array($hash_field),
                'events' => $event,
                'filterLogic'  => "[".$hash_field."] = '$new_hash'"
            );
            $q = REDCap::getData($params);
//                'array', NULL, array($cfg['MAIN_SURVEY_HASH_FIELD']), $config_event[$sub],
//                NULL,FALSE,FALSE,FALSE,$filter);
            //$this->emDebug($params, "COUNT IS ".count($q));
            $i++;
        } while ( count($q) > 0 AND $i < 10 ); //keep generating until nothing returns from get

        //$new_hash_url = $portal_url. "&h=" . $new_hash . "&sp=" . $project_id;

        return $new_hash;
    }

    function sendEmail($record, $to, $portal_url) {
        $event_id          = $this->getProjectSetting('main-config-event-name');
        $subject           = $this->getProjectSetting('portal-invite-subject');
        $from              = $this->getProjectSetting('portal-invite-from');
        $msg               = $this->getProjectSetting('portal-invite-email');
        $portal_url_label  = $this->getProjectSetting('portal-url-label');
        $repeat_instance   = NULL;
        $target_str        = "[portal-url]";

        $this->emDebug("RECORD:".$record. " / EVENTID: ".$event_id. " /REP INSTANCE: ".$repeat_instance);

        if (empty($portal_url_label)) {
            $portal_url_label = $portal_url;
        }

        $tagged_link = "<a href='{$portal_url}'>$portal_url_label</a>";

        if (strpos($msg, $target_str) !== false) {
            $msg = str_replace($target_str, $tagged_link, $msg);
        } else {
            $msg = $msg . "<br>Use this link to take the survey: ".$tagged_link;
        }

        //$this->emDebug( $email_to, $from, $subject, $msg);
        if (!isset($from)) $from = 'no-reply@stanford.edu';

        $piped_email_subject = \Piping::replaceVariablesInLabel($subject, $record, $event_id, $repeat_instance,array(), false, null, false);
        $piped_email_msg = \Piping::replaceVariablesInLabel($msg, $record, $event_id, $repeat_instance,array(), false, null, false);
        //$module->emDebug($record. "piped subject: ". $piped_email_subject);
        //$this->emDebug($record. "piped msg: ". $piped_email_msg);


        // Prepare message
        $email = new \Message();
        $email->setTo($to);
        $email->setFrom($from);
        $email->setSubject($piped_email_subject);
        $email->setBody($piped_email_msg); //format message??

        $result = $email->send();
        //$module->emDebug($to, $from, $subject, $msg, $result);

        // Send Email
        if ($result == false) {
            $this->emLog('Error sending mail: ' . $email->getSendError() . ' with ' . json_encode($email));
            return false;
        }

        return true;
    }

}
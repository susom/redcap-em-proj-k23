<?php
namespace Stanford\ProjK23;

use \REDCap;
use \Project;

require_once 'emLoggerTrait.php';

class ProjK23 extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    public $survey_record;
    public $main_pk;
    public $main_record;

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1 ) {

        $target_form         = $this->getProjectSetting('triggering-instrument');
        $config_event         = $this->getProjectSetting('main-config-event-name');

        $config_field         = $this->getProjectSetting('config-field');
        $hash_field         = $this->getProjectSetting('hash-field');

        if ($instrument == $target_form) {
            $auto_create_field    = $this->getProjectSetting('auto-create-field');



                //check that the target forms haven't already been created
                $params = array(
                    'return_format' => 'json',
                    'records' => $record,
                    'fields' => array($config_field, $hash_field, $auto_create_field),
                    'events' => $config_event
//                'redcap_repeat_instrument' => $instrument,       //this doesn't restrict
//                'redcap_repeat_instance'   => $repeat_instance   //this doesn't seem to do anything!
                );

                $q = REDCap::getData($params);
                $results = json_decode($q, true);

            //$this->emDebug($params, $results, $config_field); //exit;
            //$this->emDebug("CREATE FIELD ? : ".$results[0][$auto_create_field.'___1'] );

            //check if checkbox to autocreate is set
            if ($results[0][$auto_create_field.'___1'] == 1) {

                //check if baseline dailies rsp form is already set
                $base_key = array_search('baseline_dailies', array_column($results, 'rsp_prt_config_id'));
                $this->emDebug("RECID: " . $record . " KEY: " . $base_key . " KEY IS NULL: " . empty($base_key) . " : " . isset($base_key));
                if (empty($base_key)) {

                    $this->addRSPParticipantInfoForm('baseline_dailies', $record, $event_id, 'baseline-date-field',1);

                }

                //check if followup_dailies are set
                $fup_key = array_search('followup_dailies', array_column($results, 'rsp_prt_config_id'));
                $this->emDebug("RECID: " . $record . " KEY: " . $fup_key . " KEY IS NULL: " . empty($fup_key) . " : " . isset($fup_key));
                if (empty($fup_key)) {

                    $this->addRSPParticipantInfoForm('followup_dailies', $record, $event_id, 'class-date-field',2);

                }
            }
        }

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

        $params = array(
            'return_format'       => 'json',
            'records'             => $record,
            'fields'              => array($enrolled_form_field,$baseline_date_field, $class_date_field,$email_field,$phone_field),
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

    function addRSPParticipantInfoForm($config_id, $record, $event_id, $start_date_field, $repeat_instance) {
        $config_event         = $this->getProjectSetting('main-config-event-name');
        $target_instrument    = $this->getProjectSetting('target-instrument');

        if (!isset($target_instrument)) {
            $this->emError("Target instrument is not set in the EM config. Data will not be transferred. Set config for target-instrument.");
            return false;
        }

        $entered_data = $this->getEnteredData($record, $event_id);
        //$this->emDebug($entered_data);

        $data_array = array(
            'rsp_prt_start_date' => $entered_data[$this->getProjectSetting($start_date_field)],
            'rsp_prt_config_id' => $config_id, //i.e. 'baseline_daililes' or 'followup_dailies'
            'rsp_prt_portal_phone' => $entered_data[$this->getProjectSetting('phone-field')],
            'rsp_prt_portal_email' => $entered_data[$this->getProjectSetting('email-field')]
        );
        //save the data
        $this->saveRSPParticipantInfo('baseline_dailies', $record, $config_event, $data_array,$target_instrument);

        //trigger the hash creation and sending of the email by triggering the redcap_save_record hook on  the rsp_participant_info form
        // \Hooks::call('redcap_save_record', array($child_pid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
        \Hooks::call('redcap_save_record', array($this->getProjectId(), $record, $target_instrument, $config_event, null, null, null,$repeat_instance));
//        } else {
//            $this->emDebug("Field not set yet");
//        }

    }

    function saveRSPParticipantInfo($config_type,$record_id, $event_id, $data_array, $instrument) {
        //$instrument = 'rsp_participant_info';

        $next_instance_id = $this->getNextRepeatingInstanceID($record_id, $instrument, $event_id);
        $this->emDebug("NEXT Repeating Instance ID for  $record_id  IS ".$next_instance_id);
        $params = array(
            REDCap::getRecordIdField()                => $record_id,
            //'events'                                  => $event_id,
            'redcap_repeat_instrument'                => $instrument,
            'redcap_repeat_instance'                  => $next_instance_id
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

}
<?php

namespace Stanford\RepeatingSurveyPortal;

use REDCap;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

$url = $module->getUrl('startInviteCron.php', true, true);
echo "<br><br>This is the InviteCron Link: <br>".$url;



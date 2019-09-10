<?php

namespace Stanford\ProjK23;

use REDCap;

/** @var \Stanford\ProjK23\ProjK23 $module */


$module->emLog("------- Starting K23 Cron for  $project_id -------");
echo "------- Starting K23 Cron for $project_id -------";

$module->sendPortalInvite();


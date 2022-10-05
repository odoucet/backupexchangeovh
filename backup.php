#!/usr/bin/env php
<?php

/**
 * Backup Exchange mailboxes hosted at OVH
 *
 * @version 0.4 - 2015-04-07
 * @author Olivier Doucet (github odoucet)
 * @license http://www.php.net/license/3_01.txt  PHP License 3.01
 * @link https://www.github.com/odoucet/backupexchangeovh
 */

// We need an up to date cert.pem (to access htps://api.ovh.com)
ini_set('curl.cainfo', '/etc/ssl/cert.pem');

// how many seconds to wait on each loop when OVH API is working
define('SLEEPTIME', 10);

// Script verbosity : 0 for minimal output, 5 for medium, 10 for very verbose
define('VERBOSITY', 5);


require 'OvhApi.php';

// Check parameters.ini
$credentials = parse_ini_file('parameters.ini');
if (strlen($credentials['application_key']) < 16) {
    exit('Please fill application_key in parameters.ini');
}
if (strlen($credentials['application_secret']) < 32) {
    exit('Please fill application_secret in parameters.ini');
}
if (strlen($credentials['consumer_key']) < 32) {
    exit('Please fill consumer_key in parameters.ini');
}

if (!preg_match('@^[0-9]{1,}$@', $credentials['max_age_backup'])) {
    exit('max_age_backup should be filled (unit is "hours")');
}

if (!isset($argv[2])) {
    echo "Syntax: ".$argv[0]." <backup_folder> <account: <org>/service/<service>/account/<email>>\n";
    echo "Example: ".$argv[0].' /backup/email "private-aa-1/service/private-aa-1/account/myemail@example.com"'."\n";
    die(1);
}
// @todo check syntax argv2 + backupdir
define('BACKUPFILE', $argv[1].'/'.basename($argv[2]).'.pst');
define('ACCOUNTSTR', $argv[2]);
//---------------------------------------------------------------------------------
if (file_exists(BACKUPFILE)) {
    if (filemtime(BACKUPFILE) < time()-3600*$credentials['max_age_backup']) {
        unlink(BACKUPFILE);
    } else {
        echo "Account was already backuped less than ".$credentials['max_age_backup']." hours ago\n";
        die(0);
    }
} elseif (!is_dir(dirname(BACKUPFILE))) {
    mkdir(dirname(BACKUPFILE));
}

//---------------------------------------------------------------------------------
// First, check account exists
$ovhApi = new OvhApi(OVH_API_EU, $credentials['application_key'], $credentials['application_secret'], $credentials['consumer_key']);

$result = $ovhApi->get('/email/exchange/'.ACCOUNTSTR.'/export');

if (VERBOSITY >= 5) {
    echo 'Checking '.ACCOUNTSTR.' ...';
} elseif (VERBOSITY > 0) {
    echo "Backuping ".ACCOUNTSTR." ...\n";
}

if ($result === null || (is_object($result) && isset($result->message) && preg_match('@does not exist@', $result->message))) {
    if (VERBOSITY >= 5) {
        printf("  (no previous backup done)\n");
    }
    $newExportNeeded = true;

} elseif (!is_object($result) || !isset($result->creationDate)) {
	logError("an error occured when grabing export data.".print_r($result, 1));

} else {
    if ($result->percentComplete == 100) {
        $status = 'finished';
    } else {
        $status = $result->percentComplete.' %';
    }

    $dateBackup = new Datetime($result->creationDate);
    $hoursAgo   = floor((time() - $dateBackup->getTimestamp())/3600);
    if (VERBOSITY >= 5) {
        printf(
            "  Last backup (%10s) @ %20s -%4s hours ago\n",
            $status,
            $dateBackup->format('d-m-y H:i:s'),
            $hoursAgo
        );
    }

    // need to reset to make a new export
    // Reset everytime, because if percentComplete != 100, after several hours, it means there is a bug so reset it.
    if ($hoursAgo > $credentials['max_age_backup']) {
        if (VERBOSITY >= 5) {
            printf("  Reset export status (and wait 60 seconds) ...");
        }

        $result = $ovhApi->delete(
            '/email/exchange/'.ACCOUNTSTR.'/export'
        );

        if (isset($result->errorCode) && $result->errorCode == 'NOT_GRANTED_CALL') {
            printf(
                "Cannot reset export status for account %s because it seems ".
                "grant DELETE is missing. Please read again readme file.\n"
            );
            die(1);
        }

        for ($i = 0; $i<60; $i++) {
            sleep(1);
        }
        $newExportNeeded = true;
    } else {
        // no need to reset
        $newExportNeeded = false;
    }
}


if ($newExportNeeded) {
    $result = $ovhApi->post('/email/exchange/'.ACCOUNTSTR.'/export', null);

    // debug
    if (VERBOSITY >= 5) {
        if (isset($result->status)) {
            printf("  New export requested. Status: %s\n", $result->status);
        } else {
            logError(sprintf("  Error when requesting new export. Message: %s\n", $result->message));
	    die(1);
        }
    }
}

// infinite loop
$backupStatus = null;


while (sleep(SLEEPTIME) === 0) {
    $result = @$ovhApi->get('/email/exchange/'.ACCOUNTSTR.'/export');
    if ($result === null) {
        // error retrieving data from API
        //printf("Error retrieving info from API for %s/%s, will try again later\n", $service['service'], $email);
        continue;
    }

    if (isset($result->percentComplete) && $result->percentComplete < 100) {
        if (VERBOSITY >= 5) {
            printf("%-20s  backup done at %3s %%\r", basename(ACCOUNTSTR), $result->percentComplete);
        }

    } elseif (isset($result->percentComplete) && $result->percentComplete == 100) {
        if (!isset($backupStatus)) {
            // Yes ! Generate URL
            if (VERBOSITY > 0) {
                printf("\n%-20s is OK, generating URL ...\n", basename(ACCOUNTSTR));
            }

            $result = $ovhApi->post(
                '/email/exchange/'.ACCOUNTSTR.'/exportURL',
                null
            );
            $backupStatus = 'pendingUrl';

        } elseif ($backupStatus == 'pendingUrl') {
            // waiting for URL
            $result = @$ovhApi->get(
                '/email/exchange/'.ACCOUNTSTR.'/exportURL'
            );
            if (isset($result->url)) {
                // Yes, backup !
                if (file_exists(BACKUPFILE)) {
                    printf("URL %s already downloaded (?!) \n", $result->url);
                    die(0);
                }
                if (VERBOSITY >= 5) {
                    printf("Downloading %s to %s (with %s)\n", $result->url, BACKUPFILE, $credentials['backup_method']);
                }

                $backupStatus =  'downloadUrl';
                $downloadUrl = $result->url;

            } else {
                if (VERBOSITY >= 5) {
                    printf("Export not ready yet (no url given).");
                }

                // sometimes, OVH forgets what we ask for ...
                $result = @$ovhApi->post('/email/exchange/'.ACCOUNTSTR.'/exportURL', null);
            }
        } elseif ($backupStatus == 'downloadUrl') {
            if ($credentials['backup_method'] == 'wget') {
                if (file_exists(BACKUPFILE)) {
					unlink(BACKUPFILE); // clean it before download
				}
                passthru('wget -nc -O "'.BACKUPFILE.'" -q "'.$downloadUrl.'"');

            } elseif ($credentials['backup_method'] == 'fopen') {
                $fp = fopen($result->url, 'r');
                $out= fopen(BACKUPFILE, 'w');

                while (!feof($fp)) {
                    fwrite($out, fread($fp, 65536));
                }
                fclose($out);
                fclose($fp);
            }

            // check download OK
            if (!file_exists(BACKUPFILE) || filesize(BACKUPFILE) == 0) {
                continue; // try again
            }

            if (VERBOSITY > 0) {
                echo "Backup Done! ";
            }
            die(0);

        } else {
            logError("Case not handled for backupStatus=".$backupStatus." line ".__LINE__.': '.print_r($result, 1));
        }

    // @todo do not base behaviour on message ...
    } elseif (isset($result->message) && $result->message == 'The requested object (export) does not exist') {
        // Export has not been done. This is a problem ... Ask for new export
        $result = $ovhApi->post(
            '/email/exchange/'.$service['org'].'/service/'.
            $service['service'].'/account/'.$email.'/export',
            null
        );
        // debug
        if (VERBOSITY > 0) {
            if (isset( $result->status)) {
                printf("  New export requested. Status: %s\n", $result->status);
            } else {
                printf("  Error when requesting new export. Message: %s\n", $result->message);
            }
        }

    } elseif (isset($result->message) && $result->message == 'Internal server error') {
        // OVH had an issue ... Do nothing, try again

    } else {
        logError(ACCOUNTSTR.": Case not handled line ".__LINE__.': '.print_r($result, 1));
    }

}

function logError($str)
{
	if (VERBOSITY > 0) {
		echo $str."\n";
	}
	file_put_contents(dirname(BACKUPFILE).'/errors.log', ACCOUNTSTR.': '.$str."\n", FILE_APPEND);
	die(1);
}

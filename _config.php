<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;

if (! Director::isDev()) {
    if (isset($_GET['REQUEST_URI']) && 0 === strpos($_SERVER['REQUEST_URI'], '/dev/') || Environment::isCli()) {
        if (! Environment::getEnv('SS_MFA_SECRET_KEY')) {
            user_error(
                '
                Make sure to complete MFA Settings - add a SS_MFA_SECRET_KEY to your .env file'
            );
        }
        if (class_exists('SilverStripe\HybridSessions\HybridSession')) {
            if (! Environment::getEnv('SS_SESSION_KEY')) {
                user_error('
                    Make sure to complete HybridSession
                    Add SS_SESSION_KEY to your .env file.
                    Also see:  https://github.com/silverstripe/silverstripe-hybridsessions
                ');
            }
        }
    }
}

<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;

if (Director::isDev()) {
    if (! Environment::getEnv('SS_ALLOW_AS_DEV_SITE')) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }
        $allowed = array_filter(array_merge(explode(',', Environment::getEnv('SS_ALLOW_AS_DEV_SITE')), ['127.0.0.1', '::1']));
        if (! in_array($ip, $allowed)) {
            die('Site under urgent maintenance. Please come back soon.');
        }
        unset($allowed, $ip);
    }
} elseif (isset($_GET['REQUEST_URI']) && 0 === strpos($_SERVER['REQUEST_URI'], '/dev/') || Environment::isCli()) {
    if (! Environment::getEnv('SS_MFA_SECRET_KEY')) {
        user_error(
            '
                Make sure to complete MFA Settings - add a SS_MFA_SECRET_KEY to your .env file'
        );
    }
    if (class_exists('SilverStripe\HybridSessions\HybridSession') && ! Environment::getEnv('SS_SESSION_KEY')) {
        user_error('
                    Make sure to complete HybridSession
                    Add SS_SESSION_KEY to your .env file.
                    Also see:  https://github.com/silverstripe/silverstripe-hybridsessions
                ');
    }
}

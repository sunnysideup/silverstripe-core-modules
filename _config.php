<?php

use SilverStripe\HybridSessions\HybridSession;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;

if (Director::isDev()) {
    if (! Environment::getEnv('SS_ALLOW_AS_DEV_SITE')) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (str_contains((string) $ip, ',')) {
            $ip = explode(',', (string) $ip)[0];
        }

        $allowed = array_filter(array_merge(explode(',', Environment::getEnv('SS_ALLOW_AS_DEV_SITE')), ['127.0.0.1', '::1']));
        if (! in_array($ip, $allowed)) {
            die('Site under urgent maintenance. Please come back soon.');
        }

        unset($allowed, $ip);
    }
} elseif (isset($_GET['REQUEST_URI']) && str_starts_with((string) $_SERVER['REQUEST_URI'], '/dev/') || Environment::isCli()) {
    if (! Environment::getEnv('SS_MFA_SECRET_KEY')) {
        user_error(
            '
                Make sure to complete MFA Settings - add a SS_MFA_SECRET_KEY to your .env file'
        );
    }

    if (class_exists(HybridSession::class) && ! Environment::getEnv('SS_SESSION_KEY')) {
        user_error('
                    Make sure to complete HybridSession
                    Add SS_SESSION_KEY to your .env file.
                    Also see:  https://github.com/silverstripe/silverstripe-hybridsessions
                ');
    }
}

<?php

use SilverStripe\HybridSessions\HybridSession;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Security;

if (Director::isDev()) {
    if (!Security::allow_dev()) {
        if (Director::is_cli()) {
            echo "Set SS_ALLOW_AS_DEV_SITE in .env for local installs. ";
        }

        die('Site under urgent maintenance. Please come back soon.');
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

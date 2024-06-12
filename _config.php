<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;

if (isset($_SERVER['REQUEST_URI']) && 0 === strpos($_SERVER['REQUEST_URI'], '/admin/') && ! Environment::getEnv('SS_MFA_SECRET_KEY') && ! Director::isDev()) {
    user_error('Make sure to complete MFA Settings');
}

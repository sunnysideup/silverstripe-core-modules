<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\HybridSessions\HybridSession;

if (Director::isDev()) {
    // SS_ALLOW_AS_DEV_SITE=true (or 1) is for local development: no gate at all.
    // Any other value is a comma separated list of allowed IP addresses (e.g. to look at a remote site temporarily).
    $devSetting = trim((string) Environment::getEnv('SS_ALLOW_AS_DEV_SITE'));
    $allowAnyoneInDev = in_array(strtolower($devSetting), ['true', '1', 'yes', 'on'], true);

    // maintenance gate for non-live sites: fails closed. The command line is not reachable from the web.
    if (PHP_SAPI !== 'cli' && ! $allowAnyoneInDev) {
        // REMOTE_ADDR cannot be faked. X-Forwarded-For is NOT used, because any visitor can set it.
        $ips = [];
        if (! empty($_SERVER['REMOTE_ADDR'])) {
            $ips[] = trim((string) $_SERVER['REMOTE_ADDR']);
        }
        // behind CloudFlare, REMOTE_ADDR is the address of CloudFlare: the helper knows the real visitor address.
        $ipHelper = 'Sunnysideup\\Ecommerce\\Model\\Address\\EcommerceCountry';
        if (class_exists($ipHelper)) {
            try {
                $realIp = $ipHelper::get_ip();
                if ($realIp) {
                    $ips[] = $realIp;
                }
            } catch (Throwable $e) {
                // ignore: we still have REMOTE_ADDR
            }
        }
        $allowed = array_filter(array_map('trim', array_merge(explode(',', (string) Environment::getEnv('SS_ALLOW_AS_DEV_SITE')), ['127.0.0.1', '::1'])));
        if (array_intersect($ips, $allowed) === []) {
            die('Site under urgent maintenance. Please come back soon.');
        }
        unset($allowed, $ips, $ipHelper, $realIp);
    }

    unset($devSetting, $allowAnyoneInDev);
} elseif (isset($_GET['REQUEST_URI']) && 0 === strpos($_SERVER['REQUEST_URI'], '/dev/') || Environment::isCli()) {
    if (! Environment::getEnv('SS_MFA_SECRET_KEY')) {
        user_error(
            '
                Make sure to complete MFA Settings - add a SS_MFA_SECRET_KEY to your .env file'
        );
    }
    if (class_exists('SilverStripe\HybridSessions\HybridSession')) {
        if (!Environment::getEnv('SS_SESSION_KEY')) {
            user_error('
                Make sure to complete HybridSession
                Add SS_SESSION_KEY to your .env file.
                Also see:  https://github.com/silverstripe/silverstripe-hybridsessions
            ');
        } else {
            HybridSession::init(Environment::getEnv('SS_SESSION_KEY'));
        }
    }
}

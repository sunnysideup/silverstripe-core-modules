<?php

namespace Sunnysideup\CoreModules\Tasks;

use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Security\DefaultAdminService;
use SilverStripe\Security\Member;

class Security implements Flushable
{
    public static function flush(): void
    {
        if (! Director::is_cli()) {
            return;
        }

        try {
            DB::alteration_message("Flushing old default admins", "deleted");
            DB::alteration_message("If this causes errors, then do a dev/build from the front-end", "deleted");
            static::clearDefaultAdmin();
        } catch (Exception $exception) {

            // do nothing, just log it
            error_log("Error clearing default admin: " . $exception->getMessage());
        }
    }

    protected static function clearDefaultAdmin(): void
    {
        $service = Injector::inst()->get(DefaultAdminService::class);
        $currentDefaultAdmin = $service->findOrCreateDefaultAdmin();
        $excludeId = $currentDefaultAdmin instanceof Member ? $currentDefaultAdmin->ID : 0;

        Member::get()
            ->filter(['FirstName' => ['Default Admin', 'DefaultAdmin']])->exclude(['ID' => $excludeId])
            ->each(fn (Member $member) => $member->delete());
    }

    /**
     * Guard: halt the request when the site is in dev mode on a remote server
     * and the visitor is not in the dev allow-list.
     *
     * Call from app/_config.php inside an `if (Director::isDev())` block.
     */
    public static function allow_dev(): bool
    {
        return self::is_local_environment() || self::is_client_allowed_in_dev();
    }

    /**
     * True if the server itself is running locally
     * (loopback, private LAN, recognised local hostname, or CLI).
     */
    public static function is_local_environment(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';

        if (in_array($serverAddr, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        if (self::is_private_or_reserved_ip($serverAddr)) {
            return true;
        }

        return self::is_local_hostname(self::get_http_host());
    }

    /**
     * True if the requesting client's IP is in the dev allow-list.
     */
    public static function is_client_allowed_in_dev(): bool
    {
        return in_array(self::get_client_ip(), self::get_dev_allow_list(), true);
    }

    /**
     * Allow-list of client IPs permitted to see dev mode on a remote server.
     * Always includes loopback; extras come from SS_ALLOW_AS_DEV_SITE (CSV).
     */
    private static function get_dev_allow_list(): array
    {
        $env = (string) Environment::getEnv('SS_ALLOW_AS_DEV_SITE');
        $extra = $env === '' ? [] : array_map(trim(...), explode(',', $env));
        return array_values(array_filter(array_merge(['127.0.0.1', '::1'], $extra)));
    }

    /**
     * The client's IP, normalised (handles X-Forwarded-For comma chains).
     */
    private static function get_client_ip(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (str_contains((string) $ip, ',')) {
            $ip = explode(',', (string) $ip)[0];
        }

        return trim((string) $ip);
    }

    /**
     * The current HTTP host, lowercased and stripped of any port.
     */
    private static function get_http_host(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        return explode(':', $host)[0];
    }

    /**
     * True if the IP is in a private (RFC1918) or reserved range.
     */
    private static function is_private_or_reserved_ip(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * True if the hostname matches a recognised local-development pattern
     * (Docker, DDEV, Valet, MAMP, etc.).
     */
    private static function is_local_hostname(string $host): bool
    {
        $localTlds = ['localhost', 'test', 'local', 'localhost.localdomain', 'ddev.site', '.ss4'];
        foreach ($localTlds as $tld) {
            if ($host === $tld || str_ends_with($host, '.' . $tld)) {
                return true;
            }
        }

        return false;
    }
}

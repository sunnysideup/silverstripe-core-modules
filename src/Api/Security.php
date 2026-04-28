<?php

namespace Sunnysideup\CoreModules\Tasks;

use Exception;
use SilverStripe\Control\Director;
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
            ->each(fn(Member $member) => $member->delete());
    }
}

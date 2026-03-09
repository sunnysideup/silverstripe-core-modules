<?php

namespace Sunnysideup\CoreModules\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\DefaultAdminService;
use SilverStripe\Security\Member;

class Security implements Flushable
{
    public static function flush(): void
    {
        if (! Director::is_cli()) {
            return;
        }

        $service = Injector::inst()->get(DefaultAdminService::class);
        $currentDefaultAdmin = $service->findOrCreateDefaultAdmin();
        $excludeId = $currentDefaultAdmin instanceof Member ? $currentDefaultAdmin->ID : 0;

        Member::get()
            ->filter(['FirstName' => ['Default Admin', 'DefaultAdmin']])
            ->exclude('ID', $excludeId)
            ->each(fn(Member $member) => $member->delete());
    }
}

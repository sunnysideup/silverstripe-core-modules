<?php

namespace Sunnysideup\CoreModules\Extensions;

use SilverStripe\ORM\DataExtension;
use Sunnysideup\CoreModules\Tasks\Security;

/**
 * Removes stale default admins on dev/build, so that it also works
 * after a database has been copied from another environment.
 */
class CleanDefaultAdminMemberExtension extends DataExtension
{
    public function requireDefaultRecords()
    {
        Security::flush(true);
    }
}

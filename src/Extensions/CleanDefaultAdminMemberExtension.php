<?php

namespace Sunnysideup\CoreModules\Extensions;

use SilverStripe\Core\Extension;
use Sunnysideup\CoreModules\Tasks\Security;

/**
 * Removes stale default admins on dev/build, so that it also works
 * after a database has been copied from another environment.
 */
class CleanDefaultAdminMemberExtension extends Extension
{
    public function requireDefaultRecords()
    {
        Security::flush(true);
    }
}

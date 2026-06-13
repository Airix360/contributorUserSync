<?php

/**
 * @file classes/ContributorAddedNotify.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContributorAddedNotify
 *
 * @brief Mailable sent to a contributor letting them know they were added to a
 *   submission, naming the submission, and offering confirm / decline links.
 *   Declining removes them from the contributor list.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use PKP\mail\Mailable;
use PKP\mail\traits\Recipient;

class ContributorAddedNotify extends Mailable
{
    use Recipient;

    protected static ?string $name = 'plugins.generic.contributorUserSync.notify.name';
    protected static ?string $description = 'plugins.generic.contributorUserSync.notify.description';
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [];
    protected static array $toRoleIds = [];

    public function __construct(array $variables = [])
    {
        parent::__construct($variables);
    }
}

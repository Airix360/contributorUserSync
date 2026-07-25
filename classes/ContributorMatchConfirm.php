<?php

/**
 * @file classes/ContributorMatchConfirm.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContributorMatchConfirm
 *
 * @brief Mailable sent to an existing OJS user whose account was matched, by
 *   email, to a contributor row on someone else's submission — asking them to
 *   explicitly confirm before any ORCID or profile data is merged. A bare
 *   email match is never sufficient on its own; see
 *   ContributorSyncService::requestOrAwaitMatchConfirmation().
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use PKP\mail\Mailable;
use PKP\mail\traits\Recipient;

class ContributorMatchConfirm extends Mailable
{
    use Recipient;

    protected static ?string $name = 'plugins.generic.contributorUserSync.matchNotify.name';
    protected static ?string $description = 'plugins.generic.contributorUserSync.matchNotify.description';
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [];
    protected static array $toRoleIds = [];

    public function __construct(array $variables = [])
    {
        parent::__construct($variables);
    }
}

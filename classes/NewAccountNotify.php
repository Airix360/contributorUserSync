<?php

/**
 * @file classes/NewAccountNotify.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NewAccountNotify
 *
 * @brief Mailable sent to a contributor when 'create' mode auto-creates an
 *   OJS user account for them. Carries a password-reset/set-password link —
 *   the account's random password is never emailed.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use PKP\mail\Mailable;
use PKP\mail\traits\Recipient;

class NewAccountNotify extends Mailable
{
    use Recipient;

    protected static ?string $name = 'plugins.generic.contributorUserSync.user.newAccountMail.name';
    protected static ?string $description = 'plugins.generic.contributorUserSync.user.newAccountMail.description';
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [];
    protected static array $toRoleIds = [];

    public function __construct(array $variables = [])
    {
        parent::__construct($variables);
    }
}

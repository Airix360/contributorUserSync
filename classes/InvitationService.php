<?php

/**
 * @file classes/InvitationService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InvitationService
 *
 * @brief Sends a real OJS role-assignment invitation (OJS 3.5 invitation
 *   framework) to a contributor with no user account. The recipient gets an
 *   email with an acceptance link and creates their own account — no password
 *   is generated or sent. Only the Author role is ever offered.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use APP\core\Application;
use PKP\author\Author;
use PKP\context\Context;
use PKP\invitation\invitations\userRoleAssignment\UserRoleAssignmentInvite;

class InvitationService
{
    public function __construct(private Context $context)
    {
    }

    /**
     * Whether the core invitation framework is available (OJS 3.5+).
     */
    public static function isSupported(): bool
    {
        return class_exists(UserRoleAssignmentInvite::class);
    }

    /**
     * Dispatch an email invitation offering the Author role. Throws on failure
     * so the caller can record the error.
     */
    public function inviteContributor(Author $author, int $userGroupId): void
    {
        $email = trim((string) $author->getEmail());
        $inviter = Application::get()->getRequest()->getUser();
        if (!$inviter) {
            throw new \Exception('Invitations require an acting user to send as');
        }

        $locale = $this->context->getPrimaryLocale();
        $invitation = new UserRoleAssignmentInvite();
        $invitation->initialize(null, $this->context->getId(), $email, $inviter->getId());

        $payload = $invitation->getPayload();
        $payload->givenName = [$locale => $author->getLocalizedGivenName() ?: $email];
        $payload->familyName = [$locale => (string) $author->getLocalizedFamilyName()];
        $payload->userGroupsToAdd = [[
            'userGroupId' => $userGroupId,
            'masthead' => false,
            'dateStart' => date('Y-m-d'),
            'dateEnd' => null,
        ]];
        // NOTE: do not set shouldUseInviteData — that flag makes the manager UI
        // read inviteStagePayload (used by core's staged invite wizard), and
        // with it unset there the Users & Roles invitations tab fatals.

        if (!$invitation->updatePayload()) {
            throw new \Exception('Invitation payload failed validation');
        }
        if (!$invitation->invite()) {
            throw new \Exception('Invitation could not be dispatched');
        }
    }
}

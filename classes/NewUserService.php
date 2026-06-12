<?php

/**
 * @file classes/NewUserService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NewUserService
 *
 * @brief Creates or invites OJS user accounts for contributors that have no
 *   matching user. Safety rules (see README):
 *     - Accounts are ONLY ever given the Author role. Editor/Reviewer are never
 *       assigned here.
 *     - No generated password is emailed. "create" makes an enabled account with
 *       a random password the contributor must reset; "invite" makes a disabled
 *       account awaiting setup so onboarding goes through the activation flow.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use APP\facades\Repo;
use PKP\author\Author;
use PKP\context\Context;
use PKP\core\Core;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\user\User;

class NewUserService
{
    public function __construct(private Context $context)
    {
    }

    /**
     * Create (or invite) a user account for a contributor.
     *
     * @param bool $invite When true the account is created disabled, pending an
     *   activation/setup step rather than an emailed password.
     *
     * @return User|null The new user, or null if no Author user group exists.
     */
    public function createForContributor(Author $author, bool $invite): ?User
    {
        $authorGroupId = $this->resolveAuthorUserGroupId($author);
        if (!$authorGroupId) {
            return null;
        }

        $primaryLocale = $this->context->getPrimaryLocale();
        $user = Repo::user()->newDataObject();
        $user->setUsername($this->uniqueUsername($author->getEmail()));
        $user->setEmail($author->getEmail());
        $user->setGivenName($author->getLocalizedGivenName() ?: $author->getEmail(), $primaryLocale);
        $user->setFamilyName($author->getLocalizedFamilyName() ?: '', $primaryLocale);
        $user->setDateRegistered(Core::getCurrentDate());
        $user->setInlineHelp(1);

        // Random password the contributor never sees; they set their own later.
        $user->setPassword(Validation::encryptCredentials($user->getUsername(), Validation::generatePassword()));
        $user->setMustChangePassword(true);

        if ($invite) {
            $user->setDisabled(true);
            $user->setDisabledReason(__('plugins.generic.contributorUserSync.user.awaitingSetup'));
        }

        Repo::user()->add($user);

        // Author role only — never Editor/Reviewer.
        Repo::userGroup()->assignUserToGroup((int) $user->getId(), $authorGroupId);

        return $user;
    }

    /**
     * Choose the Author user group to assign. Prefers the contributor's own
     * author user group when it is an Author-role group; otherwise the first
     * Author-role group in the context.
     */
    private function resolveAuthorUserGroupId(Author $author): ?int
    {
        $authorGroups = Repo::userGroup()->getByRoleIds([Role::ROLE_ID_AUTHOR], $this->context->getId());

        $ids = [];
        foreach ($authorGroups as $group) {
            // Eloquent model: id is a property, not getId().
            $ids[] = (int) $group->id;
        }
        if (empty($ids)) {
            return null;
        }
        $contributorGroupId = (int) $author->getUserGroupId();
        return in_array($contributorGroupId, $ids, true) ? $contributorGroupId : $ids[0];
    }

    /**
     * Build a unique username from the email local-part.
     */
    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0])) ?: 'author';
        $candidate = $base;
        $i = 1;
        while (Repo::user()->getByUsername($candidate, true)) {
            $candidate = $base . $i++;
        }
        return $candidate;
    }
}

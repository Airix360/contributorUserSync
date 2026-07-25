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

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
     * Check-then-create races: two concurrent syncs for the same email could
     * otherwise both pass the "does this email already have a user?" check and
     * each create an account. OJS does not enforce a DB-level unique constraint
     * on email (only username), so a transaction alone can't fully close the
     * window; instead we re-check for an existing user inside the transaction
     * (narrowing it) and, if the insert itself still collides with a concurrent
     * insert (surfacing as a unique-username violation or similar DB error), we
     * catch it and fall back to a lookup rather than creating a duplicate
     * account or letting a fatal error escape.
     *
     * @param bool $invite When true the account is created disabled, pending an
     *   activation/setup step rather than an emailed password.
     *
     * @return User|null The new (or, if a race was resolved, existing) user, or
     *   null if no Author user group exists.
     */
    public function createForContributor(Author $author, bool $invite): ?User
    {
        $authorGroupId = $this->resolveAuthorUserGroupId($author);
        if (!$authorGroupId) {
            return null;
        }
        $email = trim((string) $author->getEmail());

        return DB::transaction(function () use ($author, $invite, $authorGroupId, $email) {
            // Re-check inside the transaction to narrow the race window before
            // committing to an insert.
            $existing = Repo::user()->getByEmail($email, true);
            if ($existing) {
                return $existing;
            }
            try {
                return $this->insertUser($author, $invite, $authorGroupId);
            } catch (\Throwable $e) {
                // A concurrent request most likely won the race (e.g. a
                // unique-username collision from two syncs building the same
                // candidate username at once). Fall back to a lookup instead of
                // creating a duplicate account or bubbling a fatal error.
                $existing = Repo::user()->getByEmail($email, true);
                if ($existing) {
                    return $existing;
                }
                throw $e;
            }
        });
    }

    /**
     * Insert the new user row, assign the Author role, and (for 'create' mode,
     * i.e. not $invite) email the contributor a set-password link. Never emails
     * the generated password itself.
     */
    private function insertUser(Author $author, bool $invite, int $authorGroupId): User
    {
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

        if (!$invite) {
            // 'create' mode makes an immediately-enabled account; let the
            // contributor know it exists and how to set their own password.
            $this->sendNewAccountEmail($user);
        }

        return $user;
    }

    /**
     * Email a freshly auto-created contributor with a password-reset/set-password
     * link. Best-effort: a failure here must not fail account creation.
     */
    private function sendNewAccountEmail(User $user): void
    {
        try {
            $request = Application::get()->getRequest();
            $dispatcher = $request->getDispatcher();
            $resetUrl = null;
            if (class_exists('\PKP\security\Validation') && method_exists('\PKP\security\Validation', 'generatePasswordResetHash')) {
                $hash = Validation::generatePasswordResetHash((int) $user->getId());
                $resetUrl = $dispatcher->url(
                    $request,
                    Application::ROUTE_PAGE,
                    $this->context->getPath(),
                    'login',
                    'resetPassword',
                    $user->getUsername(),
                    ['confirm' => $hash]
                );
            } else {
                // Fallback: send them to the plain login/forgot-password page.
                $resetUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $this->context->getPath(), 'login');
            }

            $mailable = new NewAccountNotify();
            $mailable->from($this->context->getData('contactEmail'), $this->context->getData('contactName'));
            $mailable->recipients([$user]);
            $mailable->subject(__('plugins.generic.contributorUserSync.user.newAccountMail.subject', [
                'journal' => $this->context->getLocalizedName(),
            ]));
            $mailable->body(__('plugins.generic.contributorUserSync.user.newAccountMail.body', [
                'name' => $user->getFullName(),
                'username' => $user->getUsername(),
                'journal' => $this->context->getLocalizedName(),
                'resetUrl' => $resetUrl,
            ]));
            Mail::send($mailable);
        } catch (\Throwable $e) {
            error_log('contributorUserSync new-account email failed: ' . $e->getMessage());
        }
    }

    /**
     * Choose the Author user group to assign. Prefers the contributor's own
     * author user group when it is an Author-role group; otherwise the first
     * Author-role group in the context.
     */
    public function resolveAuthorUserGroupId(Author $author): ?int
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

<?php

/**
 * @file classes/ContributorSyncService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContributorSyncService
 *
 * @brief Core matching and ORCID-sync logic shared by the on-save hook and the
 *   bulk sync tool. Operates on a single Author (contributor) object and, when
 *   $apply is true, mutates it in place; the caller is responsible for
 *   persistence. All behaviour is governed by the resolved settings array so
 *   the same code path drives both live edits and dry-run previews.
 *
 *   Important OJS design note: core OJS authors have no userId column, so the
 *   "link" to a user is stored as a plugin-owned author setting
 *   (self::SETTING_USER_ID) in the author_settings table.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use APP\facades\Repo;
use APP\plugins\generic\contributorUserSync\ContributorUserSyncPlugin;
use PKP\author\Author;
use PKP\context\Context;
use PKP\user\User;

class ContributorSyncService
{
    /**
     * Author-setting keys. These MUST be registered on the author schema via
     * the Schema::get::author hook (see the plugin class) or EntityDAO::update
     * silently strips them on save.
     */
    public const SETTING_USER_ID = 'contributorUserSyncUserId';
    public const SETTING_STATUS = 'contributorUserSyncStatus';
    public const SETTING_STATUS_AT = 'contributorUserSyncStatusAt';

    /**
     * A user matched by email that pre-dates this sync run (i.e. not an account
     * this plugin just created) is only a *candidate* until that user confirms
     * the match via the emailed confirm/decline link. Until confirmed, this
     * holds the candidate user id and SETTING_USER_ID is left untouched — no
     * ORCID or profile data is merged, and nothing is attached to the
     * submission. See ContributorApprovalHandler::confirmMatch()/declineMatch().
     */
    public const SETTING_PENDING_USER_ID = 'contributorUserSyncPendingUserId';

    /** Approval key for the pending-match confirm/decline link (separate from
     *  NotificationService::SETTING_APPROVAL_KEY, which guards an unrelated
     *  "you were added as a contributor" flow). */
    public const SETTING_MATCH_KEY = 'contributorUserSyncMatchKey';

    /**
     * @param array $settings Resolved plugin settings (see ContributorUserSyncPlugin::resolveSettings()).
     * @param ContributorUserSyncPlugin|null $plugin Needed to persist cross-submission
     *   state (per-email invite suppression). Optional only so lightweight/manual
     *   callers can omit it; when omitted, invite suppression falls back to the
     *   per-contributor status stamp (its old, per-submission-only behaviour).
     */
    public function __construct(private array $settings, private Context $context, private ?ContributorUserSyncPlugin $plugin = null)
    {
    }

    public function isEnabled(): bool
    {
        return !empty($this->settings['syncEnabled']) && ($this->settings['syncMode'] ?? 'nothing') !== 'nothing';
    }

    /**
     * Process one contributor. Records outcomes to $report. When $apply is true,
     * mutates $author in place (caller persists). Returns true if the author was
     * (or, in preview mode, would be) modified.
     */
    public function processAuthor(Author $author, int $submissionId, SyncReport $report, bool $apply = true): bool
    {
        $report->beginContributor();
        $name = $author->getFullName(false) ?: '';
        $email = trim((string) $author->getEmail());
        $changed = false;

        try {
            // A contributor's email may have been corrected since a previous
            // sync linked (or proposed linking) them to a user. If that link no
            // longer matches the current email, it's stale — clear it rather
            // than leaving the author row pointing at an unrelated account.
            $changed = $this->reconcileStaleLink($author, $email, $report, $apply) || $changed;

            if (!$this->isRoleEligible($author)) {
                $report->record(SyncReport::SKIPPED_INELIGIBLE_ROLE);
                return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
            }

            if ($email === '') {
                $report->record(SyncReport::SKIPPED_NO_EMAIL);
                return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
            }

            $user = Repo::user()->getByEmail($email, true);
            $isPreexistingAccount = (bool) $user;
            if (!$user) {
                $mode = $this->settings['syncMode'] ?? 'link';
                if ($mode === 'invite' && $apply) {
                    $changed = $this->inviteContributor($author, $report) || $changed;
                    // No account exists until the invitation is accepted, so
                    // there is nothing to link yet.
                    return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
                }
                if ($mode === 'create' && $apply) {
                    $user = $this->createMissingUser($author, $report);
                }
                if (!$user) {
                    // 'link' mode, preview mode, or creation not possible.
                    $report->record(SyncReport::SKIPPED_NO_USER);
                    return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
                }
                // Freshly created by this very call — no independent owner
                // exists yet to confirm, so no confirmation gate applies.
                $isPreexistingAccount = false;
                $changed = true;
            }

            // --- Same-submission duplicate-match guard ------------------------
            // Never let two different contributor rows on the same submission
            // resolve (or be proposed to resolve) to the same user — that would
            // let one verified ORCID / profile attach to multiple co-author rows.
            if ($this->userAlreadyLinkedToSibling($author, $user)) {
                $report->record(SyncReport::SKIPPED_DUPLICATE_MATCH, $user->getUsername());
                return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
            }

            // --- Confirmation gate for pre-existing accounts ------------------
            // A bare email match is not sufficient to write ORCID/profile data
            // into someone else's account or the submission: the matched user
            // must explicitly confirm via the emailed confirm/decline link
            // before anything below this point runs for them.
            if ($isPreexistingAccount) {
                $linkedUserId = (int) $author->getData(self::SETTING_USER_ID);
                if ($linkedUserId !== (int) $user->getId()) {
                    $changed = $this->requestOrAwaitMatchConfirmation($author, $user, $submissionId, $report, $apply) || $changed;
                    return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
                }
            }

            // --- Link contributor to the matched (and, if applicable, confirmed)
            // user --------------------------------------------------------------
            $report->record(SyncReport::MATCHED_USER, $user->getUsername());
            if ((int) $author->getData(self::SETTING_USER_ID) !== (int) $user->getId()) {
                $report->record(SyncReport::LINKED);
                if ($apply) {
                    $author->setData(self::SETTING_USER_ID, (int) $user->getId());
                }
                $changed = true;
            }

            // --- Optionally refresh contributor names/affiliation from the
            // user profile (fill-empty only, never overwrites) ----------------
            if (!empty($this->settings['updateContributorFromUser'])) {
                $changed = $this->copyNamesFromUser($author, $user, $apply) || $changed;
                $changed = $this->copyAffiliationFromUser($author, $user, $submissionId, $apply) || $changed;
            }

            // --- Optionally fill empty user profile names from the contributor
            // (off by default; never overwrites an existing profile name) -----
            if (!empty($this->settings['updateUserFromContributor'])) {
                $this->copyNamesToUser($author, $user, $apply);
            }

            // --- ORCID auto-sync --------------------------------------------
            if (!empty($this->settings['orcidAutoSync'])) {
                $changed = $this->syncOrcid($author, $user, $report, $apply) || $changed;
            }
        } catch (\Throwable $e) {
            $report->record(SyncReport::ERROR, $e->getMessage());
        }

        return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
    }

    /**
     * Clear a previously-linked (or pending) user match if it no longer
     * corresponds to the contributor's current email — e.g. the email was a
     * typo that got corrected, or was reassigned to a different person. Returns
     * true if the author was changed.
     */
    private function reconcileStaleLink(Author $author, string $email, SyncReport $report, bool $apply): bool
    {
        $changed = false;

        $linkedId = (int) $author->getData(self::SETTING_USER_ID);
        if ($linkedId) {
            $linkedUser = Repo::user()->get($linkedId);
            if (!$linkedUser || !$this->emailsMatch((string) $linkedUser->getEmail(), $email)) {
                if ($apply) {
                    $author->setData(self::SETTING_USER_ID, null);
                }
                $report->record(SyncReport::LINK_CLEARED, (string) $linkedId);
                $changed = true;
            }
        }

        $pendingId = (int) $author->getData(self::SETTING_PENDING_USER_ID);
        if ($pendingId) {
            $pendingUser = Repo::user()->get($pendingId);
            if (!$pendingUser || !$this->emailsMatch((string) $pendingUser->getEmail(), $email)) {
                if ($apply) {
                    $author->setData(self::SETTING_PENDING_USER_ID, null);
                    $author->setData(self::SETTING_MATCH_KEY, null);
                }
                $changed = true;
            }
        }

        return $changed;
    }

    private function emailsMatch(string $a, string $b): bool
    {
        return $a !== '' && strcasecmp($a, $b) === 0;
    }

    /**
     * Is $user already linked (confirmed) or awaiting confirmation on a
     * *different* contributor row of the same submission? Prevents the same
     * verified ORCID / profile from attaching to multiple co-author entries.
     */
    private function userAlreadyLinkedToSibling(Author $author, ?User $user): bool
    {
        if (!$user) {
            return false;
        }
        $publicationId = (int) $author->getData('publicationId');
        if (!$publicationId) {
            return false;
        }
        $userId = (int) $user->getId();
        $siblings = Repo::author()->getCollector()
            ->filterByPublicationIds([$publicationId])
            ->getMany();
        foreach ($siblings as $sibling) {
            if ((int) $sibling->getId() === (int) $author->getId()) {
                continue;
            }
            if ((int) $sibling->getData(self::SETTING_USER_ID) === $userId
                || (int) $sibling->getData(self::SETTING_PENDING_USER_ID) === $userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * A pre-existing user was matched by email but has not yet confirmed the
     * match. Send (or note a still-pending) confirmation request; never link or
     * merge data until the matched user has explicitly confirmed. Returns true
     * if the author was changed.
     */
    private function requestOrAwaitMatchConfirmation(Author $author, User $user, int $submissionId, SyncReport $report, bool $apply): bool
    {
        $pendingUserId = (int) $author->getData(self::SETTING_PENDING_USER_ID);
        $hasPendingKey = (bool) $author->getData(self::SETTING_MATCH_KEY);
        $wasDeclinedByThisUser = $pendingUserId === (int) $user->getId()
            && $author->getData(self::SETTING_STATUS) === SyncReport::SKIPPED_MATCH_DECLINED;

        if ($wasDeclinedByThisUser && empty($this->settings['forceResend'])) {
            // This exact user already declined; don't nag them on every save.
            $report->record(SyncReport::SKIPPED_MATCH_DECLINED, $user->getUsername());
            return false;
        }

        if ($pendingUserId === (int) $user->getId() && $hasPendingKey && empty($this->settings['forceResend'])) {
            // Already asked this same user and awaiting a reply; don't re-send
            // on every save.
            $report->record(SyncReport::MATCH_PENDING_CONFIRMATION, $user->getUsername());
            return false;
        }

        if (!$apply) {
            $report->record(SyncReport::MATCH_PENDING_CONFIRMATION, $user->getUsername());
            return false;
        }

        $submission = $submissionId ? Repo::submission()->get($submissionId) : null;
        if (!$submission) {
            $report->record(SyncReport::MATCH_PENDING_CONFIRMATION, $user->getUsername());
            return false;
        }

        try {
            $service = new NotificationService($this->context);
            $key = $service->requestMatchConfirmation($author, $user, $submission, true);
            if ($key) {
                $author->setData(self::SETTING_PENDING_USER_ID, (int) $user->getId());
                $author->setData(self::SETTING_MATCH_KEY, $key);
                $report->record(SyncReport::MATCH_PENDING_CONFIRMATION, $user->getUsername());
                return true;
            }
        } catch (\Throwable $e) {
            $report->record(SyncReport::ERROR, $e->getMessage());
        }
        return false;
    }

    /**
     * Send an email invitation (OJS 3.5 invitation framework) to a contributor
     * with no account. On OJS 3.4 falls back to a disabled placeholder account.
     * Re-invitations are suppressed per-email (not just per-submission — the
     * same person listed on two submissions should not get two invitations),
     * with settings[forceResend] available for explicit manual resends. Returns
     * true if the author was changed.
     */
    private function inviteContributor(Author $author, SyncReport $report): bool
    {
        $email = trim((string) $author->getEmail());
        $suppression = $this->plugin ? new InvitationSuppressionService($this->plugin, $this->context->getId()) : null;
        $alreadyInvited = $suppression
            ? $suppression->isSuppressed($email)
            // Fallback when no plugin reference is available: the old,
            // per-submission-only check.
            : $author->getData(self::SETTING_STATUS) === SyncReport::INVITATION_SENT;
        if ($alreadyInvited && empty($this->settings['forceResend'])) {
            $report->record(SyncReport::SKIPPED_NO_USER, 'invitation pending');
            return false;
        }
        try {
            $newUserService = new NewUserService($this->context);
            $groupId = $newUserService->resolveAuthorUserGroupId($author);
            if (!$groupId) {
                throw new \Exception('no_author_user_group');
            }
            if (InvitationService::isSupported()) {
                (new InvitationService($this->context))->inviteContributor($author, $groupId);
            } elseif (!$newUserService->createForContributor($author, true)) {
                throw new \Exception('no_author_user_group');
            }
            $suppression?->markInvited($email);
            $report->record(SyncReport::INVITATION_SENT, $author->getEmail());
            return true; // persist the invitationSent status stamp
        } catch (\Throwable $e) {
            $report->record(SyncReport::ERROR, $e->getMessage());
            return false;
        }
    }

    /**
     * Create a user account for a contributor with no match. Returns the new
     * user, or null if creation was not possible (recorded as an error).
     */
    private function createMissingUser(Author $author, SyncReport $report): ?User
    {
        $user = (new NewUserService($this->context))->createForContributor($author, false);
        if (!$user) {
            $report->record(SyncReport::ERROR, 'no_author_user_group');
            return null;
        }
        $report->record(SyncReport::USER_CREATED, $user->getUsername());
        return $user;
    }

    /**
     * Copy a verified ORCID iD from the matched user into the contributor.
     * Honours overwrite and manual-ORCID settings. Returns true if changed.
     */
    private function syncOrcid(Author $author, User $user, SyncReport $report, bool $apply): bool
    {
        $userOrcid = $user->getOrcid();
        $verified = $user->hasVerifiedOrcid();
        $allowManual = !empty($this->settings['orcidAllowManual']);

        // Nothing usable on the user side.
        if (empty($userOrcid) || (!$verified && !$allowManual)) {
            $report->record(SyncReport::SKIPPED_NO_VERIFIED_ORCID);
            return $this->applyNoVerifiedOrcidAction($author, $report, $apply);
        }

        $existing = $author->getOrcid();
        $alreadyInSync = !empty($existing)
            && $existing === $userOrcid
            && ($author->hasVerifiedOrcid() === $verified);
        if ($alreadyInSync) {
            return false; // contributor already carries this exact (verified) iD
        }

        // Refuse to clobber a different, already-present contributor ORCID.
        if (!empty($existing) && $existing !== $userOrcid && empty($this->settings['orcidOverwrite'])) {
            $report->record(SyncReport::ORCID_SKIPPED_EXISTING, $existing);
            return false;
        }

        if ($apply) {
            if ($verified) {
                // Copy the full OAuth proof set so the contributor reads as verified.
                $author->setVerifiedOrcidOAuthData([
                    'orcid' => $userOrcid,
                    'orcidIsVerified' => true,
                    'orcidAccessDenied' => $user->getData('orcidAccessDenied'),
                    'orcidAccessToken' => $user->getData('orcidAccessToken'),
                    'orcidAccessScope' => $user->getData('orcidAccessScope'),
                    'orcidRefreshToken' => $user->getData('orcidRefreshToken'),
                    'orcidAccessExpiresOn' => $user->getData('orcidAccessExpiresOn'),
                ]);
            } else {
                // Manual (admin-permitted) copy: value only, never marked verified.
                $author->setOrcid($userOrcid);
                $author->setOrcidVerified(false);
            }
        }
        $report->record(SyncReport::ORCID_SYNCED, $userOrcid . ($verified ? '' : ' (manual)'));
        return true;
    }

    /**
     * When the matched user has no verified ORCID, react per the configured
     * policy: do nothing, warn the editor, or send the contributor core's
     * ORCID authorization-request email. Returns true if the author changed.
     */
    private function applyNoVerifiedOrcidAction(Author $author, SyncReport $report, bool $apply): bool
    {
        $action = $this->settings['orcidNoVerifiedAction'] ?? 'nothing';
        // 'warn' and 'nothing' are surfaced purely through the report/feedback.
        if ($action !== 'request' || !$apply) {
            return false;
        }
        if ($author->getData('orcidVerificationRequested') && empty($this->settings['forceResend'])) {
            return false; // already asked; don't nag on every save
        }
        if (
            !class_exists('\PKP\jobs\orcid\SendAuthorMail')
            || !\PKP\orcid\OrcidManager::isEnabled($this->context)
        ) {
            // ORCID not configured (or pre-3.5 core); nothing to send.
            return false;
        }
        // updateAuthor=true so the email token persists even on async queues;
        // the surrounding save cycle is hook-free for direct DAO updates.
        dispatch(new \PKP\jobs\orcid\SendAuthorMail($author, $this->context, true));
        $author->setData('orcidVerificationRequested', true);
        $report->record(SyncReport::ORCID_REQUEST_SENT, $author->getEmail());
        return true;
    }

    /**
     * Fill empty contributor given/family names from the user profile.
     * Never overwrites an existing contributor name. Returns true if changed.
     */
    private function copyNamesFromUser(Author $author, User $user, bool $apply): bool
    {
        $changed = false;
        foreach (['GivenName', 'FamilyName'] as $field) {
            $userValues = $user->{'get' . $field}(null) ?? [];
            $authorValues = $author->{'get' . $field}(null) ?? [];
            foreach ($userValues as $locale => $value) {
                if (!empty($value) && empty($authorValues[$locale] ?? null)) {
                    if ($apply) {
                        $author->{'set' . $field}($value, $locale);
                    }
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    /**
     * Copy the user profile's affiliation to a contributor that has none, using
     * core's user→author affiliation migration (OJS 3.5 structured
     * affiliations). Never overwrites an existing contributor affiliation.
     */
    private function copyAffiliationFromUser(Author $author, User $user, int $submissionId, bool $apply): bool
    {
        if (!method_exists($author, 'getAffiliations') || !empty($author->getAffiliations())) {
            return false;
        }
        if (empty($user->getData('affiliation')) || !$submissionId) {
            return false;
        }
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return false;
        }
        $affiliation = Repo::affiliation()->migrateUserAffiliation($user, $submission, $this->context);
        if (!$affiliation) {
            return false;
        }
        if ($apply) {
            $author->setAffiliations([$affiliation]);
        }
        return true;
    }

    /**
     * Fill empty user profile given/family names from the contributor and
     * persist the user. Never overwrites an existing profile name. The user is
     * a separate entity, so saving it here cannot recurse into the author hooks.
     */
    private function copyNamesToUser(Author $author, User $user, bool $apply): void
    {
        $changed = false;
        foreach (['GivenName', 'FamilyName'] as $field) {
            $authorValues = $author->{'get' . $field}(null) ?? [];
            $userValues = $user->{'get' . $field}(null) ?? [];
            foreach ($authorValues as $locale => $value) {
                if (!empty($value) && empty($userValues[$locale] ?? null)) {
                    $user->{'set' . $field}($value, $locale);
                    $changed = true;
                }
            }
        }
        if ($changed && $apply) {
            Repo::user()->edit($user);
        }
    }

    /**
     * Is this contributor's role (author user group) eligible for sync?
     * An empty eligibleRoles set means "all roles".
     */
    private function isRoleEligible(Author $author): bool
    {
        $eligible = $this->settings['eligibleRoles'] ?? [];
        if (empty($eligible)) {
            return true;
        }
        return in_array((int) $author->getUserGroupId(), array_map('intval', $eligible), true);
    }

    /**
     * Persist a lightweight status stamp for editor feedback and close out the
     * report row. Returns $changed unchanged for caller convenience.
     */
    private function finish(Author $author, int $submissionId, string $name, string $email, SyncReport $report, bool $apply, bool $changed): bool
    {
        $outcomes = $report->currentOutcomes();
        if ($apply && !empty($outcomes)) {
            $newStatus = end($outcomes);
            // A status transition counts as a change so callers persist it and
            // the editor-facing badge reflects the latest outcome.
            if ($author->getData(self::SETTING_STATUS) !== $newStatus) {
                $changed = true;
            }
            $author->setData(self::SETTING_STATUS, $newStatus);
            $author->setData(self::SETTING_STATUS_AT, date('Y-m-d H:i:s'));
        }
        $report->endContributor((int) $author->getId(), $submissionId, $name, $email);
        return $changed;
    }
}

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
use PKP\author\Author;
use PKP\context\Context;
use PKP\user\User;

class ContributorSyncService
{
    /** Author-setting key holding the matched user id (the plugin's "link"). */
    public const SETTING_USER_ID = 'contributorUserSync::userId';
    /** Author-setting key holding the last sync status code (editor feedback). */
    public const SETTING_STATUS = 'contributorUserSync::status';
    /** Author-setting key holding the last sync timestamp. */
    public const SETTING_STATUS_AT = 'contributorUserSync::statusAt';

    /**
     * @param array $settings Resolved plugin settings (see ContributorUserSyncPlugin::resolveSettings()).
     */
    public function __construct(private array $settings, private Context $context)
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
            if (!$this->isRoleEligible($author)) {
                $report->record(SyncReport::SKIPPED_INELIGIBLE_ROLE);
                return $this->finish($author, $submissionId, $name, $email, $report, $apply, false);
            }

            if ($email === '') {
                $report->record(SyncReport::SKIPPED_NO_EMAIL);
                return $this->finish($author, $submissionId, $name, $email, $report, $apply, false);
            }

            $user = Repo::user()->getByEmail($email, true);
            if (!$user) {
                $mode = $this->settings['syncMode'] ?? 'link';
                if (in_array($mode, ['invite', 'create'], true) && $apply) {
                    $user = $this->createMissingUser($author, $mode === 'invite', $report);
                }
                if (!$user) {
                    // 'link' mode, preview mode, or creation not possible.
                    $report->record(SyncReport::SKIPPED_NO_USER);
                    return $this->finish($author, $submissionId, $name, $email, $report, $apply, $changed);
                }
                $changed = true;
            }

            // --- Link contributor to the matched user ------------------------
            $report->record(SyncReport::MATCHED_USER, $user->getUsername());
            if ((int) $author->getData(self::SETTING_USER_ID) !== (int) $user->getId()) {
                $report->record(SyncReport::LINKED);
                if ($apply) {
                    $author->setData(self::SETTING_USER_ID, (int) $user->getId());
                }
                $changed = true;
            }

            // --- Optionally refresh contributor names from the user profile --
            if (!empty($this->settings['updateContributorFromUser'])) {
                $changed = $this->copyNamesFromUser($author, $user, $apply) || $changed;
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
     * Create or invite a user account for a contributor with no match. Returns
     * the new user, or null if creation was not possible (recorded as an error).
     */
    private function createMissingUser(Author $author, bool $invite, SyncReport $report): ?User
    {
        $user = (new NewUserService($this->context))->createForContributor($author, $invite);
        if (!$user) {
            $report->record(SyncReport::ERROR, 'no_author_user_group');
            return null;
        }
        $report->record($invite ? SyncReport::INVITATION_SENT : SyncReport::USER_CREATED, $user->getUsername());
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
            $this->applyNoVerifiedOrcidAction($author, $report, $apply);
            return false;
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
     * policy: do nothing, warn the editor, or flag an ORCID connection request.
     */
    private function applyNoVerifiedOrcidAction(Author $author, SyncReport $report, bool $apply): void
    {
        $action = $this->settings['orcidNoVerifiedAction'] ?? 'nothing';
        if ($action === 'request' && $apply && !$author->getData('orcidVerificationRequested')) {
            $author->setData('orcidVerificationRequested', true);
        }
        // 'warn' and 'nothing' are surfaced purely through the report/feedback.
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
            $author->setData(self::SETTING_STATUS, end($outcomes));
            $author->setData(self::SETTING_STATUS_AT, date('Y-m-d H:i:s'));
        }
        $report->endContributor((int) $author->getId(), $submissionId, $name, $email);
        return $changed;
    }
}

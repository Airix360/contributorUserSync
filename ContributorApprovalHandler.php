<?php

/**
 * @file ContributorApprovalHandler.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContributorApprovalHandler
 *
 * @brief Public page handler for contributor confirm / decline links. Declining
 *   removes the contributor from the submission's contributor list.
 */

namespace APP\plugins\generic\contributorUserSync;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\template\TemplateManager;
use APP\plugins\generic\contributorUserSync\classes\ContributorSyncService;
use APP\plugins\generic\contributorUserSync\classes\NotificationService;
use APP\plugins\generic\contributorUserSync\classes\SyncReport;

class ContributorApprovalHandler extends Handler
{
    public function __construct(private ContributorUserSyncPlugin $plugin)
    {
    }

    /**
     * Contributor confirms they belong on the submission. No data change; just
     * clears the pending key so the link can't be replayed.
     */
    public function confirm($args, $request)
    {
        $this->act($request, false);
    }

    /**
     * Contributor declines: remove them from the contributor list.
     */
    public function decline($args, $request)
    {
        $this->act($request, true);
    }

    /**
     * The matched user confirms they are who a contributor row's email
     * resolved to: finalise the link and run a live sync so the ORCID/profile
     * merges that were gated on this confirmation happen immediately.
     */
    public function confirmMatch($args, $request)
    {
        $this->actMatch($request, true);
    }

    /**
     * The matched user declines: the account is a stranger to this submission.
     * No data is merged and no link is created.
     */
    public function declineMatch($args, $request)
    {
        $this->actMatch($request, false);
    }

    private function act($request, bool $remove): void
    {
        $authorId = (int) $request->getUserVar('authorId');
        $key = (string) $request->getUserVar('key');
        $author = $authorId ? Repo::author()->get($authorId) : null;

        $templateMgr = TemplateManager::getManager($request);
        $valid = $author
            && $key !== ''
            && hash_equals((string) $author->getData(NotificationService::SETTING_APPROVAL_KEY), $key);

        if (!$valid) {
            $templateMgr->assign('cusMessage', __('plugins.generic.contributorUserSync.approval.invalid'));
        } elseif ($remove) {
            Repo::author()->delete($author);
            $templateMgr->assign('cusMessage', __('plugins.generic.contributorUserSync.approval.declined'));
        } else {
            $author->setData(NotificationService::SETTING_APPROVAL_KEY, null);
            Repo::author()->edit($author, []);
            $templateMgr->assign('cusMessage', __('plugins.generic.contributorUserSync.approval.confirmed'));
        }

        $templateMgr->assign('pageTitle', 'plugins.generic.contributorUserSync.approval.title');
        $templateMgr->display($this->plugin->getTemplateResource('approval.tpl'));
    }

    private function actMatch($request, bool $accept): void
    {
        $authorId = (int) $request->getUserVar('authorId');
        $key = (string) $request->getUserVar('key');
        $author = $authorId ? Repo::author()->get($authorId) : null;

        $templateMgr = TemplateManager::getManager($request);
        $valid = $author
            && $key !== ''
            && hash_equals((string) $author->getData(ContributorSyncService::SETTING_MATCH_KEY), $key);

        if (!$valid) {
            $templateMgr->assign('cusMessage', __('plugins.generic.contributorUserSync.approval.invalid'));
            $templateMgr->assign('pageTitle', 'plugins.generic.contributorUserSync.approval.title');
            $templateMgr->display($this->plugin->getTemplateResource('approval.tpl'));
            return;
        }

        $pendingUserId = (int) $author->getData(ContributorSyncService::SETTING_PENDING_USER_ID);
        $author->setData(ContributorSyncService::SETTING_MATCH_KEY, null);

        if ($accept && $pendingUserId) {
            $author->setData(ContributorSyncService::SETTING_PENDING_USER_ID, null);
            $author->setData(ContributorSyncService::SETTING_USER_ID, $pendingUserId);
        } elseif (!$accept) {
            // Deliberately keep SETTING_PENDING_USER_ID (who declined) so a
            // future sync run recognises this exact user already declined and
            // does not re-send a confirmation request for them.
            $author->setData(ContributorSyncService::SETTING_STATUS, SyncReport::SKIPPED_MATCH_DECLINED);
        }
        // Suspend the on-save hook for this bookkeeping save: the real merge
        // (below) runs through a controlled, single processAuthor() call
        // instead of letting the generic Author::edit hook race it.
        ContributorUserSyncPlugin::$suspendHook = true;
        try {
            Repo::author()->edit($author, []);
        } finally {
            ContributorUserSyncPlugin::$suspendHook = false;
        }

        if ($accept && $pendingUserId) {
            // Re-run the sync now that the match is confirmed, so the
            // ORCID/profile merges the confirmation was gating on actually
            // happen (rather than waiting for the next unrelated author edit).
            $this->finalizeConfirmedMatch($author);
            $templateMgr->assign('cusMessage', __('plugins.generic.contributorUserSync.approval.matchConfirmed'));
        } else {
            $templateMgr->assign('cusMessage', __('plugins.generic.contributorUserSync.approval.matchDeclined'));
        }

        $templateMgr->assign('pageTitle', 'plugins.generic.contributorUserSync.approval.title');
        $templateMgr->display($this->plugin->getTemplateResource('approval.tpl'));
    }

    private function finalizeConfirmedMatch($author): void
    {
        $publication = Repo::publication()->get((int) $author->getData('publicationId'));
        $submission = $publication ? Repo::submission()->get((int) $publication->getData('submissionId')) : null;
        if (!$submission) {
            return;
        }
        $context = Application::getContextDAO()->getById((int) $submission->getData('contextId'));
        if (!$context) {
            return;
        }
        $settings = $this->plugin->resolveSettings($context->getId());
        $service = new ContributorSyncService($settings, $context, $this->plugin);
        $report = new SyncReport();
        if ($service->processAuthor($author, (int) $submission->getId(), $report, true)) {
            ContributorUserSyncPlugin::$suspendHook = true;
            try {
                Repo::author()->edit($author, []);
            } finally {
                ContributorUserSyncPlugin::$suspendHook = false;
            }
        }
    }
}

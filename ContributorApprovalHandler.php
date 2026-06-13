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

use APP\facades\Repo;
use APP\handler\Handler;
use APP\template\TemplateManager;
use APP\plugins\generic\contributorUserSync\classes\NotificationService;

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

        $templateMgr->assign('pageTitle', __('plugins.generic.contributorUserSync.approval.title'));
        $templateMgr->display($this->plugin->getTemplateResource('approval.tpl'));
    }
}

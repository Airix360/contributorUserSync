<?php

/**
 * @file classes/BulkSyncTool.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BulkSyncTool
 *
 * @brief Scans existing submissions in a context and runs ContributorSyncService
 *   over every contributor, in either preview (dry-run) or apply mode, producing
 *   a SyncReport. Multi-journal safe: it only ever touches the given context.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use APP\facades\Repo;
use APP\plugins\generic\contributorUserSync\ContributorUserSyncPlugin;
use PKP\context\Context;

class BulkSyncTool
{
    public function __construct(private ContributorSyncService $service, private Context $context)
    {
    }

    /**
     * Run the bulk scan.
     *
     * @param bool $apply  When false, nothing is persisted (preview).
     * @param int  $limit  Maximum submissions to scan (0 = no limit).
     */
    public function run(bool $apply, int $limit = 0): SyncReport
    {
        $report = new SyncReport();

        $collector = Repo::submission()->getCollector()
            ->filterByContextIds([$this->context->getId()]);
        $submissionIds = $collector->getIds();

        $processed = 0;
        foreach ($submissionIds as $submissionId) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            $publicationIds = Repo::publication()->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->getIds()
                ->all();
            if (empty($publicationIds)) {
                continue;
            }

            $authors = Repo::author()->getCollector()
                ->filterByPublicationIds($publicationIds)
                ->getMany();

            foreach ($authors as $author) {
                $changed = $this->service->processAuthor($author, (int) $submissionId, $report, $apply);
                if ($apply && $changed) {
                    // Suspend the on-save hook so persisting here does not recurse.
                    ContributorUserSyncPlugin::$suspendHook = true;
                    try {
                        Repo::author()->edit($author, []);
                    } finally {
                        ContributorUserSyncPlugin::$suspendHook = false;
                    }
                }
            }
        }

        return $report;
    }
}

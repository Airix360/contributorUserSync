<?php

/**
 * @file classes/NotificationService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NotificationService
 *
 * @brief Sends the "you were added to a submission" notification with confirm /
 *   decline links. Stores a per-contributor approval key (author setting) used
 *   to authenticate the public confirm/decline handler.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\Mail;
use PKP\author\Author;
use PKP\context\Context;
use PKP\submission\PKPSubmission;

class NotificationService
{
    public const SETTING_APPROVAL_KEY = 'contributorUserSyncApprovalKey';

    public function __construct(private Context $context)
    {
    }

    /**
     * Notify a freshly added contributor. Stores the approval key on the author
     * (caller persists when inside a save hook). Returns true if a mail was sent.
     */
    public function notifyAdded(Author $author, PKPSubmission $submission, bool $apply): bool
    {
        $email = trim((string) $author->getEmail());
        if ($email === '' || $author->getData(self::SETTING_APPROVAL_KEY)) {
            return false; // no address, or already notified
        }
        $key = bin2hex(random_bytes(16));
        if ($apply) {
            $author->setData(self::SETTING_APPROVAL_KEY, $key);
        }

        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $base = ['authorId' => $author->getId(), 'key' => $key];
        $confirmUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $this->context->getPath(), 'contributorApproval', 'confirm', null, $base);
        $declineUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $this->context->getPath(), 'contributorApproval', 'decline', null, $base);

        $title = $submission->getCurrentPublication()->getLocalizedTitle();
        $journal = $this->context->getLocalizedName();
        $subject = __('plugins.generic.contributorUserSync.notify.subject', ['title' => $title]);
        $body = __('plugins.generic.contributorUserSync.notify.body', [
            'name' => $author->getFullName(),
            'title' => $title,
            'journal' => $journal,
            'confirmUrl' => $confirmUrl,
            'declineUrl' => $declineUrl,
        ]);

        if ($apply) {
            $mailable = new ContributorAddedNotify();
            $mailable->from($this->context->getData('contactEmail'), $this->context->getData('contactName'));
            $mailable->recipients([$author]);
            $mailable->subject($subject)->body($body);
            Mail::send($mailable);
        }
        return true;
    }
}

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
use PKP\user\User;

class NotificationService
{
    public const SETTING_APPROVAL_KEY = 'contributorUserSyncApprovalKey';

    public function __construct(private Context $context)
    {
    }

    /**
     * Ask a pre-existing user (matched to a contributor row by email) to
     * explicitly confirm before the plugin merges any data — ORCID or profile
     * fields — into their account or the submission. Sent to the matched
     * user's own account email, i.e. the same address that produced the match,
     * so the confirmation lands with the actual account owner regardless of who
     * typed the email into the submission form.
     *
     * @return string|null The generated approval key (caller persists it on the
     *   author alongside the pending user id), or null if no mail was sent.
     */
    public function requestMatchConfirmation(Author $author, User $user, PKPSubmission $submission, bool $apply): ?string
    {
        $key = bin2hex(random_bytes(16));
        if (!$apply) {
            return $key;
        }

        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $base = ['authorId' => $author->getId(), 'key' => $key];
        $confirmUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $this->context->getPath(), 'contributorApproval', 'confirmMatch', null, $base);
        $declineUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $this->context->getPath(), 'contributorApproval', 'declineMatch', null, $base);

        $title = $submission->getCurrentPublication()->getLocalizedTitle();
        $journal = $this->context->getLocalizedName();
        $subject = __('plugins.generic.contributorUserSync.matchNotify.subject', ['title' => $title]);
        $body = __('plugins.generic.contributorUserSync.matchNotify.body', [
            'name' => $user->getFullName(),
            'title' => $title,
            'journal' => $journal,
            'confirmUrl' => $confirmUrl,
            'declineUrl' => $declineUrl,
        ]);

        $mailable = new ContributorMatchConfirm();
        $mailable->from($this->context->getData('contactEmail'), $this->context->getData('contactName'));
        // Sent to the matched user's own account email — not to whatever
        // address the submitter typed — so only the real account owner can act.
        $mailable->recipients([$user]);
        $mailable->subject($subject)->body($body);
        Mail::send($mailable);

        return $key;
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

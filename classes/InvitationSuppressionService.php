<?php

/**
 * @file classes/InvitationSuppressionService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InvitationSuppressionService
 *
 * @brief Tracks "was this email already invited recently" per-email (not
 *   per-submission), so the same person listed as a contributor on two
 *   different submissions does not get two independent invitation emails.
 *   Backed by a single plugin setting (a small map of email => timestamp) per
 *   context, with entries expiring after a TTL so re-invites eventually
 *   become possible again (e.g. if the first invite was lost or ignored).
 */

namespace APP\plugins\generic\contributorUserSync\classes;

use APP\plugins\generic\contributorUserSync\ContributorUserSyncPlugin;

class InvitationSuppressionService
{
    private const SETTING_KEY = 'invitationSuppressionMap';

    /** Re-inviting the same email is suppressed for this long. */
    private const TTL_SECONDS = 7 * 24 * 60 * 60; // 7 days

    public function __construct(private ContributorUserSyncPlugin $plugin, private int $contextId)
    {
    }

    /**
     * Was this email already invited within the TTL window (in this context)?
     */
    public function isSuppressed(string $email): bool
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return false;
        }
        $map = $this->load();
        return isset($map[$email]) && (time() - (int) $map[$email]) < self::TTL_SECONDS;
    }

    /**
     * Record that an invitation was just sent to this email, and opportunistically
     * prune expired entries so the setting doesn't grow without bound.
     */
    public function markInvited(string $email): void
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return;
        }
        $map = $this->load();
        $map[$email] = time();
        foreach ($map as $key => $timestamp) {
            if ((time() - (int) $timestamp) >= self::TTL_SECONDS) {
                unset($map[$key]);
            }
        }
        $this->plugin->updateSetting($this->contextId, self::SETTING_KEY, $map, 'object');
    }

    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @return array<string,int> email => unix timestamp last invited
     */
    private function load(): array
    {
        return (array) ($this->plugin->getSetting($this->contextId, self::SETTING_KEY) ?? []);
    }
}

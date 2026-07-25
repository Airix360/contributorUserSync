<?php

/**
 * @file tests/audit-fixes.php
 *
 * Source-level regression checks for the fixes in
 * /Users/hendrix/OJS/plugin-audit-report.md ("contributorUserSync" section).
 *
 * This plugin's runtime classes extend/use OJS/PKP framework classes that are
 * not available outside a full OJS install, so — matching the sibling
 * ojs-magic-login repo's test style — these checks assert on the *source*
 * rather than instantiating the classes. They exist to catch regressions of
 * each numbered fix, not to re-verify OJS core behaviour.
 *
 * Requires no OJS installation or database.
 * Exit code 0 = all tests passed. Exit code 1 = one or more tests failed.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;

function ok(bool $result, string $label): void
{
    global $passed, $failed;
    if ($result) {
        echo "\033[32m  PASS\033[0m  $label\n";
        $passed++;
    } else {
        echo "\033[31m  FAIL\033[0m  $label\n";
        $failed++;
    }
}

function src(string $relative): string
{
    global $root;
    $path = "$root/$relative";
    $content = @file_get_contents($path);
    if ($content === false) {
        echo "::error::Could not read $relative\n";
        exit(1);
    }
    return $content;
}

$syncService = src('classes/ContributorSyncService.php');
$notificationService = src('classes/NotificationService.php');
$approvalHandler = src('ContributorApprovalHandler.php');
$plugin = src('ContributorUserSyncPlugin.php');
$newUserService = src('classes/NewUserService.php');
$settingsForm = src('SettingsForm.php');
$settingsTpl = src('templates/settingsForm.tpl');
$settingsXml = src('settings.xml');
$syncReport = src('classes/SyncReport.php');

// ── 1 (HIGH): confirmation gate before merging any data on a bare email match

echo "\n[1. HIGH — matched-user confirmation gate]\n";

ok(
    strpos($syncService, 'SETTING_PENDING_USER_ID') !== false
    && strpos($syncService, 'SETTING_MATCH_KEY') !== false,
    'ContributorSyncService tracks a pending (unconfirmed) match separately from the confirmed link'
);
ok(
    (bool) preg_match('/function requestOrAwaitMatchConfirmation\(/', $syncService),
    'ContributorSyncService has a dedicated match-confirmation request path'
);
ok(
    (bool) preg_match(
        '/if\s*\(\$isPreexistingAccount\)\s*\{.*?requestOrAwaitMatchConfirmation.*?return \$this->finish/s',
        $syncService
    ),
    'processAuthor() returns immediately (no merge) for a pre-existing account until confirmed'
);
// The merge/link block must be reachable only *after* the confirmation gate,
// i.e. it must not run unconditionally right after the email lookup.
$gatePos = strpos($syncService, 'if ($isPreexistingAccount) {');
$linkPos = strpos($syncService, "report->record(SyncReport::MATCHED_USER");
ok(
    $gatePos !== false && $linkPos !== false && $gatePos < $linkPos,
    'the confirmation gate runs before the matched-user link/merge block'
);
ok(
    strpos($notificationService, 'function requestMatchConfirmation(') !== false,
    'NotificationService reuses the confirm/decline email mechanism for match confirmation'
);
ok(
    strpos($notificationService, '$mailable->recipients([$user])') !== false,
    'the match-confirmation email is addressed to the matched account\'s own email, not the submitter-typed one'
);
ok(
    strpos($approvalHandler, 'function confirmMatch(') !== false
    && strpos($approvalHandler, 'function declineMatch(') !== false,
    'ContributorApprovalHandler exposes confirmMatch()/declineMatch() alongside the existing confirm()/decline()'
);
ok(
    strpos($plugin, "'confirmMatch', 'declineMatch'") !== false,
    'the confirmMatch/declineMatch ops are routed by ContributorUserSyncPlugin::onLoadHandler()'
);
ok(
    (bool) preg_match('/SETTING_USER_ID,\s*\$pendingUserId\)/', $approvalHandler),
    'confirming a match is the only place that promotes a pending match to SETTING_USER_ID (the trusted link)'
);
ok(
    (bool) preg_match('/onAuthorSchema.*?ContributorSyncService::SETTING_PENDING_USER_ID.*?ContributorSyncService::SETTING_MATCH_KEY/s', $plugin),
    'the two new author settings (pending user id, match key) are registered on the author schema, or EntityDAO silently strips them on save'
);

// ── 2 (Medium): same-submission duplicate-match guard

echo "\n[2. Medium — same-submission duplicate-match guard]\n";

ok(
    strpos($syncService, 'function userAlreadyLinkedToSibling(') !== false,
    'ContributorSyncService checks sibling contributor rows before linking/proposing a match'
);
ok(
    strpos($syncService, 'SyncReport::SKIPPED_DUPLICATE_MATCH') !== false,
    'a duplicate match is recorded as a distinct, visible outcome'
);
ok(
    strpos($syncReport, "SKIPPED_DUPLICATE_MATCH = 'skippedDuplicateMatch'") !== false,
    'SyncReport defines the SKIPPED_DUPLICATE_MATCH outcome'
);
// The duplicate guard must run before both the confirmation gate and the link/merge block.
$dupPos = strpos($syncService, 'userAlreadyLinkedToSibling($author, $user)');
ok(
    $dupPos !== false && $dupPos < $gatePos && $dupPos < $linkPos,
    'the duplicate-match guard runs before both the confirmation gate and the link/merge block'
);

// ── 3 (Medium): stale-link clearing on email change

echo "\n[3. Medium — stale-link clearing]\n";

ok(
    strpos($syncService, 'function reconcileStaleLink(') !== false,
    'ContributorSyncService has a stale-link reconciliation step'
);
ok(
    strpos($syncService, 'reconcileStaleLink($author, $email, $report, $apply)') !== false,
    'reconcileStaleLink() is invoked from processAuthor()'
);
ok(
    strpos($syncService, 'SyncReport::LINK_CLEARED') !== false,
    'a cleared stale link is recorded as a distinct, visible outcome'
);
// reconcileStaleLink() must run at the very top of processAuthor(), before the
// role/email eligibility short-circuits, so a link is cleared even if the new
// email would otherwise cause an early return.
$reconcilePos = strpos($syncService, '$this->reconcileStaleLink(');
$roleCheckPos = strpos($syncService, 'isRoleEligible($author)');
ok(
    $reconcilePos !== false && $roleCheckPos !== false && $reconcilePos < $roleCheckPos,
    'reconcileStaleLink() runs before the role-eligibility / empty-email short-circuits'
);

// ── 4 (Medium, race): atomic user creation

echo "\n[4. Medium — atomic account creation]\n";

ok(
    strpos($newUserService, 'DB::transaction(') !== false,
    'NewUserService::createForContributor() wraps check-then-create in a transaction'
);
ok(
    (bool) preg_match('/DB::transaction\(function \(\).*?getByEmail\(\$email, true\)/s', $newUserService),
    'the existing-user check is re-run inside the transaction to narrow the race window'
);
ok(
    (bool) preg_match('/catch \(\\\\Throwable \$e\) \{.*?getByEmail\(\$email, true\).*?throw \$e;/s', $newUserService),
    'a failed insert (e.g. a concurrent unique-username collision) falls back to a lookup instead of bubbling a fatal error or creating a duplicate'
);

// ── 5 (Low-Medium): per-email invite suppression

echo "\n[5. Low-Medium — per-email invite suppression]\n";

$suppressionServiceFile = "$root/classes/InvitationSuppressionService.php";
ok(file_exists($suppressionServiceFile), 'classes/InvitationSuppressionService.php exists');
$suppressionService = @file_get_contents($suppressionServiceFile) ?: '';
ok(
    strpos($suppressionService, 'function isSuppressed(string $email)') !== false
    && strpos($suppressionService, 'function markInvited(string $email)') !== false,
    'InvitationSuppressionService exposes per-email isSuppressed()/markInvited()'
);
ok(
    strpos($suppressionService, 'TTL_SECONDS') !== false,
    'suppression entries expire after a TTL rather than suppressing forever'
);
ok(
    strpos($syncService, 'InvitationSuppressionService($this->plugin, $this->context->getId())') !== false,
    'ContributorSyncService::inviteContributor() consults the per-email suppression service'
);
// The old per-submission-only check (author status stamp) must no longer be
// the *only* gate — it's kept only as a no-plugin-reference fallback.
ok(
    strpos($syncService, '$suppression->isSuppressed($email)') !== false,
    'invite suppression is keyed by email, not solely by the per-contributor-row status stamp'
);

// ── 6 (Low): "an account was created for you" email in create mode

echo "\n[6. Low — new-account email with set-password link, not a raw password]\n";

$newAccountMailFile = "$root/classes/NewAccountNotify.php";
ok(file_exists($newAccountMailFile), 'classes/NewAccountNotify.php mailable exists');
ok(
    strpos($newUserService, 'function sendNewAccountEmail(') !== false,
    'NewUserService sends a new-account notification'
);
ok(
    (bool) preg_match('/if \(!\$invite\) \{.*?sendNewAccountEmail\(\$user\);/s', $newUserService),
    'the new-account email is only sent in \'create\' mode (immediately-enabled accounts), not \'invite\' mode'
);
ok(
    strpos($newUserService, 'generatePasswordResetHash') !== false,
    'the email links to a password-reset flow'
);
ok(
    strpos($newUserService, '$user->getPassword()') === false
    && strpos($newUserService, 'generatePassword()') !== false
    && strpos($newUserService, "'password' =>") === false,
    'the generated (random) password is never embedded in the outgoing email'
);

// ── 7 (Code quality): dead createUserRole field removed, unused constant removed

echo "\n[7. Code quality — dead settings field and unused constant removed]\n";

ok(strpos($plugin, "'createUserRole'") === false, 'the dead createUserRole key removed from ContributorUserSyncPlugin::resolveSettings()');
ok(strpos($settingsForm, "'createUserRole' =>") === false, 'createUserRole removed from SettingsForm::SCALAR_SETTINGS');
ok(strpos($settingsTpl, 'id="createUserRole"') === false, 'the disabled createUserRole field removed from settingsForm.tpl');
ok(strpos($settingsXml, '<name>createUserRole</name>') === false, 'createUserRole removed from settings.xml defaults');
ok(
    strpos($syncReport, 'ORCID_SKIPPED_UNVERIFIED') === false,
    'unused SyncReport::ORCID_SKIPPED_UNVERIFIED constant removed'
);
// Author is still the mandatory baseline for every auto-created/invited account.
ok(
    strpos($newUserService, 'Role::ROLE_ID_AUTHOR') !== false,
    'Author remains the unconditional baseline role for created/invited accounts'
);

// ── 8 (Feature): safe, manager-configurable created-user role (Reviewer opt-in)
//
// createUserRole was dead on arrival (introduced in the MVP commit, never read
// by role-resolution logic) and was removed rather than wired up, because
// wiring an *arbitrary* stored string straight into role assignment would
// have let a plugin setting escalate a submission-wizard-triggered account to
// any role, including Journal Manager/Site Admin. This section replaces it
// with a real, narrowly-scoped feature: a boolean opt-in (not a free-form
// role picker) that can only ever add Reviewer on top of the mandatory
// Author role.

echo "\n[8. Feature — safe manager-configurable created-user role]\n";

ok(
    (bool) preg_match('/ALLOWED_EXTRA_ROLE_IDS\s*=\s*\[Role::ROLE_ID_REVIEWER\]/', $newUserService),
    'NewUserService declares a closed allow-list of extra roles containing only Reviewer'
);
ok(
    strpos($newUserService, 'ROLE_ID_MANAGER') === false
    && strpos($newUserService, 'ROLE_ID_SITE_ADMIN') === false
    && strpos($newUserService, 'ROLE_ID_SUB_EDITOR') === false,
    'NewUserService never references Manager/Site Admin/Sub Editor role constants — nothing above Reviewer is reachable in code'
);
ok(
    strpos($newUserService, 'function createForContributor(Author $author, bool $invite, bool $alsoAssignReviewer = false)') !== false,
    'createForContributor() takes a plain boolean Reviewer opt-in, not an arbitrary role id/string'
);
ok(
    strpos($newUserService, 'function resolveReviewerUserGroupId(') !== false,
    'NewUserService can resolve a Reviewer user group, independently of the Author group resolver'
);
ok(
    (bool) preg_match('/assignUserToGroup\(\(int\) \$user->getId\(\), \$authorGroupId\);\s*\n\s*if \(\$reviewerGroupId\)/', $newUserService),
    'Author is assigned unconditionally; Reviewer is only assigned when a group id was actually resolved'
);
ok(
    strpos($settingsForm, "'createUserAllowReviewer' => 'bool'") !== false,
    'createUserAllowReviewer is persisted as a plain bool via SettingsForm — the client can only ever POST true/false, never a role id'
);
ok(
    strpos($plugin, "'createUserAllowReviewer' => (bool) \$get('createUserAllowReviewer', false)") !== false,
    'the setting defaults to false (Author-only) when unset'
);
ok(
    strpos($settingsXml, '<name>createUserAllowReviewer</name>') !== false,
    'createUserAllowReviewer ships with a safe (false) default in settings.xml'
);
ok(
    strpos($settingsTpl, 'id="createUserAllowReviewer"') !== false,
    'the Reviewer opt-in checkbox is exposed in the settings template'
);
ok(
    strpos($syncService, "empty(\$this->settings['createUserAllowReviewer'])") !== false,
    'ContributorSyncService reads the opt-in and threads it through both the create-mode and invite-mode paths'
);
$invitationService = src('classes/InvitationService.php');
ok(
    strpos($invitationService, 'function inviteContributor(Author $author, int $userGroupId, ?int $reviewerGroupId = null)') !== false,
    'InvitationService accepts an optional, pre-resolved Reviewer group id rather than a role name/string'
);
ok(
    strpos($invitationService, 'ROLE_ID_MANAGER') === false
    && strpos($invitationService, 'ROLE_ID_SITE_ADMIN') === false,
    'InvitationService never references Manager/Site Admin role constants'
);

// ── 9 (Enhancement): reject malformed contributor emails before account creation

echo "\n[9. Enhancement — email validation before auto-creating/inviting an account]\n";

ok(
    strpos($newUserService, 'FILTER_VALIDATE_EMAIL') !== false,
    'NewUserService validates the contributor email format before creating an account'
);
ok(
    (bool) preg_match('/filter_var\(\$email, FILTER_VALIDATE_EMAIL\) === false\)\s*\{\s*\n\s*return null;/', $newUserService),
    'an invalid email causes createForContributor() to bail out with null rather than creating a broken account'
);

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 50) . "\n";
$total = $passed + $failed;
if ($failed === 0) {
    echo "\033[32mAll $total tests passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m$failed of $total tests FAILED.\033[0m\n\n";
    exit(1);
}

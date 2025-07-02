<?php

namespace APP\plugins\generic\contributorUserSync;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\template\PKPTemplateManager as TemplateManager;
use PKP\security\Validation;
use PKP\facades\Repo;
use DAORegistry;
use PKP\mail\MailTemplate;

class ContributorUserSyncPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) return false;

        Hook::add('Submission::saveMetadata', [$this, 'syncContributorsToUsers']);
        Hook::add('Form::SubmissionMetadataForm::initData', [$this, 'modifyContributorRoles']);
        return true;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.contributorUserSync.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.contributorUserSync.description');
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);

        import('lib.pkp.classes.linkAction.request.AjaxModal');
        $router = $request->getRouter();

        $actions[] = new \PKP\linkAction\LinkAction(
            'settings',
            new \PKP\linkAction\request\AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                $this->getDisplayName(),
                'modal_manage'
            ),
            __('manager.plugins.settings')
        );

        return $actions;
    }

    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new ContributorUserSyncSettingsForm($this);
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute($request);
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));

            case 'syncContributors':
                $context = $request->getContext();
                $contextId = $context->getId();
                $offset = (int) $request->getUserVar('offset');
                $chunkSize = 20;
                $synced = (int) $request->getUserVar('synced');
                $skipped = (int) $request->getUserVar('skipped');

                $allSubmissions = Repo::submission()->getCollector()
                    ->filterByContextIds([$contextId])
                    ->getMany();
                $total = count($allSubmissions);
                $submissions = array_slice($allSubmissions, $offset, $chunkSize);
                $chunkProcessed = 0;
                foreach ($submissions as $submission) {
                    foreach ($submission->getAuthors() as $author) {
                        $email = $author->getEmail();
                        if (!$email) {
                            $skipped++;
                            continue;
                        }

                        $existingUser = Repo::user()->getCollector()->filterByEmail($email)->getMany()->first();
                        $overwrite = $this->getSetting($contextId, 'overwriteExisting');

                        if ($existingUser && !$overwrite) {
                            $skipped++;
                            continue;
                        }

                        $allowedRoles = $this->getSetting($contextId, 'customContributorRoles');
                        if (!empty($allowedRoles)) {
                            $authorRole = $author->getUserGroupRole() ?? 'Author';
                            if (!in_array($authorRole, $allowedRoles)) {
                                $skipped++;
                                continue;
                            }
                        }

                        if (!$existingUser) {
                            $username = strtolower(explode('@', $email)[0]) . rand(1000, 9999);
                            $password = Validation::generatePassword((int) $this->getSetting($contextId, 'defaultPasswordLength') ?: 12);
                            $user = Repo::user()->newDataObject();
                            $user->setUsername($username);
                            $user->setEmail($email);
                            $user->setGivenName($author->getGivenName(null), null);
                            $user->setFamilyName($author->getFamilyName(null), null);
                            $user->setPreferredPublicName($author->getFullName(), null);
                            $user->setAuthId(0);
                            $user->setPassword(Validation::encryptCredentials($username, $password));
                            $user->setInlineHelp(true);
                            Repo::user()->add($user);
                            Repo::user()->assignRole($contextId, $user->getId(), $this->getSetting($contextId, 'defaultRole') ?? 'author');

                            if ((bool) $this->getSetting($contextId, 'notifySynced')) {
                                $mail = new MailTemplate('USER_REGISTER');
                                $mail->setReplyTo($request->getSite()->getContactEmail(), $request->getSite()->getContactName());
                                $mail->assignParams([
                                    'username' => $username,
                                    'password' => $password,
                                    'userFullName' => $user->getFullName(),
                                    'contextName' => $request->getContext()->getData('name', $user->getLocale()),
                                    'loginUrl' => $request->url($request->getContext()->getPath(), 'login')
                                ]);
                                $mail->addRecipient($email);
                                $mail->send();
                            }

                            $synced++;
                        } else {
                            $existingUser->setGivenName($author->getGivenName(null), null);
                            $existingUser->setFamilyName($author->getFamilyName(null), null);
                            $existingUser->setPreferredPublicName($author->getFullName(), null);
                            Repo::user()->edit($existingUser);
                            $skipped++;
                        }
                    }

                    $chunkProcessed++;
                }

                return new JSONMessage(true, [
                    'offset' => $offset + $chunkProcessed,
                    'synced' => $synced,
                    'skipped' => $skipped,
                    'total' => $total,
                    'finished' => ($offset + $chunkProcessed) >= $total,
                    'success' => true
                ]);
        }

        return parent::manage($args, $request);
    }

    public function syncContributorsToUsers($hookName, $args)
    {
        [$submission, $request] = $args;

        $context = $request->getContext();
        $contextId = $context->getId();

        $enabled = $this->getSetting($contextId, 'enabled');
        if (!$enabled) return;

        $contributors = $submission->getAuthors();
        $overwrite = $this->getSetting($contextId, 'overwriteExisting');
        $notify = $this->getSetting($contextId, 'notifySynced');
        $defaultRole = $this->getSetting($contextId, 'defaultRole') ?? 'author';
        $allowedRoles = $this->getSetting($contextId, 'customContributorRoles');

        foreach ($contributors as $author) {
            $email = $author->getEmail();
            if (!$email) continue;

            if (!empty($allowedRoles)) {
                $authorRole = $author->getUserGroupRole() ?? 'Author';
                if (!in_array($authorRole, $allowedRoles)) continue;
            }

            $existingUser = Repo::user()->getCollector()->filterByEmail($email)->getMany()->first();
            if ($existingUser && !$overwrite) continue;

            if (!$existingUser) {
                $username = strtolower(explode('@', $email)[0]) . rand(1000, 9999);
                $password = Validation::generatePassword((int) $this->getSetting($contextId, 'defaultPasswordLength') ?: 12);

                $user = Repo::user()->newDataObject();
                $user->setUsername($username);
                $user->setEmail($email);
                $user->setGivenName($author->getGivenName(null), null);
                $user->setFamilyName($author->getFamilyName(null), null);
                $user->setPreferredPublicName($author->getFullName(), null);
                $user->setAuthId(0);
                $user->setPassword(Validation::encryptCredentials($username, $password));
                $user->setInlineHelp(true);

                Repo::user()->add($user);
                Repo::user()->assignRole($contextId, $user->getId(), $defaultRole);

                if ($notify) {
                    $mail = new MailTemplate('USER_REGISTER');
                    $mail->setReplyTo($request->getSite()->getContactEmail(), $request->getSite()->getContactName());
                    $mail->assignParams([
                        'username' => $username,
                        'password' => $password,
                        'userFullName' => $user->getFullName(),
                        'contextName' => $request->getContext()->getData('name', $user->getLocale()),
                        'loginUrl' => $request->url($request->getContext()->getPath(), 'login')
                    ]);
                    $mail->addRecipient($email);
                    $mail->send();
                }
            } elseif ($existingUser && $overwrite) {
                $existingUser->setGivenName($author->getGivenName(null), null);
                $existingUser->setFamilyName($author->getFamilyName(null), null);
                $existingUser->setPreferredPublicName($author->getFullName(), null);
                Repo::user()->edit($existingUser);
            }
        }

        return false;
    }

    public function modifyContributorRoles($hookName, $args)
    {
        [$form] = $args;
        $contextId = $form->getContextId();
        $customRoles = $this->getSetting($contextId, 'availableContributorRoles') ?? [];
        if (!is_array($customRoles)) {
            $customRoles = [];
        }

        if ($customRoles) {
            $roleOptions = [];
            foreach ($customRoles as $role) {
                $roleOptions[$role] = __("plugins.generic.contributorUserSync.role.$role");
            }
            $form->setData('contributorRoleOptions', $roleOptions);
        }

        return false;
    }
}

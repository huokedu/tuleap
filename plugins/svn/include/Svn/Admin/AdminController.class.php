<?php
/**
 * Copyright (c) Enalean, 2016 - 2017. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tuleap\Svn\Admin;

use Feedback;
use Rule_Email;
use Tuleap\Svn\Notifications\CannotAddUgroupsNotificationException;
use Tuleap\Svn\Notifications\CannotAddUsersNotificationException;
use Tuleap\Svn\Notifications\NotificationsEmailsBuilder;
use Tuleap\Svn\Notifications\NotificationListBuilder;
use Tuleap\Svn\ServiceSvn;
use Tuleap\Svn\Repository\RepositoryManager;
use Tuleap\Svn\Repository\Repository;
use Tuleap\Svn\Repository\HookConfig;
use Project;
use HTTPRequest;
use Tuleap\User\InvalidEntryInAutocompleterCollection;
use Tuleap\User\RequestFromAutocompleter;
use UGroupManager;
use UserManager;
use Valid_Int;
use Valid_String;
use CSRFSynchronizerToken;
use Logger;

class AdminController
{

    private $repository_manager;
    private $mail_header_manager;
    private $mail_notification_manager;
    /**
     * @var NotificationListBuilder
     */
    private $notification_list_builder;
    /**
     * @var NotificationsEmailsBuilder
     */
    private $emails_builder;
    /**
     * @var UserManager
     */
    private $user_manager;
    /**
     * @var UGroupManager
     */
    private $ugroup_manager;

    public function __construct(
        MailHeaderManager $mail_header_manager,
        RepositoryManager $repository_manager,
        MailNotificationManager $mail_notification_manager,
        Logger $logger,
        NotificationListBuilder $notification_list_builder,
        NotificationsEmailsBuilder $emails_builder,
        UserManager $user_manager,
        UGroupManager $ugroup_manager
    ) {
        $this->repository_manager        = $repository_manager;
        $this->mail_header_manager       = $mail_header_manager;
        $this->mail_notification_manager = $mail_notification_manager;
        $this->logger                    = $logger;
        $this->notification_list_builder = $notification_list_builder;
        $this->emails_builder            = $emails_builder;
        $this->user_manager              = $user_manager;
        $this->ugroup_manager            = $ugroup_manager;
    }

    private function generateToken(Project $project, Repository $repository) {
        return new CSRFSynchronizerToken(SVN_BASE_URL."/?group_id=".$project->getid(). '&repo_id='.$repository->getId()."&action=display-mail-notification");
    }

    public function displayMailNotification(ServiceSvn $service, HTTPRequest $request) {
        $repository = $this->repository_manager->getById($request->get('repo_id'), $request->getProject());

        $token = $this->generateToken($request->getProject(), $repository);

        $mail_header           = $this->mail_header_manager->getByRepository($repository);
        $notifications_details = $this->mail_notification_manager->getByRepository($repository);

        $title = $GLOBALS['Language']->getText('global', 'Administration');

        $service->renderInPage(
            $request,
            $repository->getName() .' – '. $title,
            'admin/mail_notification',
            new MailNotificationPresenter(
                $repository,
                $request->getProject(),
                $token,
                $title,
                $mail_header,
                $this->notification_list_builder->getNotificationsPresenter($notifications_details, $this->emails_builder)
            )
        );
    }

    public function saveMailHeader(HTTPRequest $request) {
        $repository = $this->repository_manager->getById($request->get('repo_id'), $request->getProject());

        $token = $this->generateToken($request->getProject(), $repository);
        $token->check();

        $repo_name = $request->get("form_mailing_header");
        $vHeader = new Valid_String('form_mailing_header');
        if($request->valid($vHeader)) {
            $mail_header = new MailHeader($repository, $repo_name);
            try {
                $this->mail_header_manager->create($mail_header);
                $GLOBALS['Response']->addFeedback('info', $GLOBALS['Language']->getText('plugin_svn_admin_notification','upd_header_success'));
            } catch (CannotCreateMailHeaderException $e) {
                $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_svn_admin_notification','upd_header_fail'));
            }
        } else {
            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_svn_admin_notification','upd_header_fail'));
        }

        $GLOBALS['Response']->redirect(SVN_BASE_URL.'/?'. http_build_query(
            array('group_id' => $request->getProject()->getid(),
                  'repo_id'  => $request->get('repo_id'),
                  'action'   => 'display-mail-notification'
            )));
    }

    public function saveMailingList(HTTPRequest $request)
    {
        $repository = $this->repository_manager->getById($request->get('repo_id'), $request->getProject());

        $token = $this->generateToken($request->getProject(), $repository);
        $token->check();

        $notification_to_add    = $request->get('notification_add');
        $notification_to_update = $request->get('notification_update');

        if ($notification_to_update
            && ! empty($notification_to_update)) {
            $this->updateMailingList($request, $repository, $notification_to_update);
        } else {
            $this->createMailingList($request, $repository, $notification_to_add);
        }
    }

    public function createMailingList(HTTPRequest $request, Repository $repository, $notification_to_add)
    {
        $form_path       = $notification_to_add['path'];
        $valid_path      = new Valid_String($form_path);
        $invalid_entries = new InvalidEntryInAutocompleterCollection();
        $autocompleter   = $this->getAutocompleter($request->getProject(), $invalid_entries, $notification_to_add['emails']);

        $is_path_valid = $request->valid($valid_path) && $form_path !== '';
        $invalid_entries->generateWarningMessageForInvalidEntries();

        if (! $is_path_valid) {
            $this->addFeedbackPathError();
            $this->redirectOnDisplayNotification($request);
            return;
        }

        if ($this->mail_notification_manager->isAnExistingPath($repository, 0, $form_path)) {
            $GLOBALS['Response']->addFeedback(
                Feedback::WARN,
                sprintf(
                    dgettext(
                        'tuleap-svn',
                        "The path '%s' already exists."
                    ),
                    $form_path
                )
            );
            $this->redirectOnDisplayNotification($request);
            return;
        }

        if (! $autocompleter->isNotificationEmpty()) {
            $mail_notification = new MailNotification(
                0,
                $repository,
                $this->emails_builder->transformNotificationEmailsArrayAsString($autocompleter->getEmails()),
                $form_path
            );
            try {
                $notification_id = $this->mail_notification_manager->create($mail_notification);
                $this->mail_notification_manager->notificationAddUsers($notification_id, $autocompleter);
                $this->mail_notification_manager->notificationAddUgroups($notification_id, $autocompleter);
                $GLOBALS['Response']->addFeedback(
                    Feedback::INFO,
                    $GLOBALS['Language']->getText('plugin_svn_admin_notification', 'upd_email_success')
                );
            } catch (CannotCreateMailHeaderException $e) {
                $GLOBALS['Response']->addFeedback(
                    Feedback::ERROR,
                    $GLOBALS['Language']->getText('plugin_svn_admin_notification', 'upd_email_error')
                );
            } catch (CannotAddUsersNotificationException $e) {
                $this->addFeedbackUsersNotAdded($e->getUsersNotAdded());
            } catch (CannotAddUgroupsNotificationException $e) {
                $this->addFeedbackUgroupsNotAdded($e->getUgroupsNotAdded());
            }
        } else {
            $GLOBALS['Response']->addFeedback(
                Feedback::ERROR,
                $GLOBALS['Language']->getText('plugin_svn_admin_notification', 'upd_email_error')
            );
        }

        $this->redirectOnDisplayNotification($request);
    }

    public function updateMailingList(HTTPRequest $request, Repository $repository, $notification_to_update)
    {
        $notification_ids = array_keys($notification_to_update);
        $notification_id  = $notification_ids[0];
        $new_path         = $notification_to_update[$notification_id]['path'];
        $emails           = $notification_to_update[$notification_id]['emails'];
        $valid_path       = new Valid_String($new_path);

        $invalid_entries = new InvalidEntryInAutocompleterCollection();
        $autocompleter   = $this->getAutocompleter($request->getProject(), $invalid_entries, $emails);

        $is_path_valid = $request->valid($valid_path) && $new_path !== '';
        $invalid_entries->generateWarningMessageForInvalidEntries();

        if (! $is_path_valid) {
            $this->addFeedbackPathError($request);
            $this->redirectOnDisplayNotification($request);
            return;
        }

        $notification = $this->mail_notification_manager->getByIdAndRepository($repository, $notification_id);

        if (! $notification) {
            $GLOBALS['Response']->addFeedback(
                Feedback::WARN,
                sprintf(
                    dgettext(
                        'tuleap-svn',
                        "Notification to update doesn't exist."
                    ),
                    $new_path
                )
            );
            $this->redirectOnDisplayNotification($request);
            return;
        }

        if ($notification->getPath() !== $new_path
            && $this->mail_notification_manager->isAnExistingPath($repository, $notification_id, $new_path)) {
            $GLOBALS['Response']->addFeedback(
                Feedback::WARN,
                sprintf(
                    dgettext(
                        'tuleap-svn',
                        "The path '%s' already exists."
                    ),
                    $new_path
                )
            );
            $this->redirectOnDisplayNotification($request);
            return;
        }

        $email_notification = new MailNotification(
            $notification_id,
            $repository,
            $this->emails_builder->transformNotificationEmailsArrayAsString($autocompleter->getEmails()),
            $new_path
        );
        try {
            if (! $autocompleter->isNotificationEmpty()) {
                $this->mail_notification_manager->update($email_notification, $autocompleter);
            } else {
                $this->mail_notification_manager->removeByNotificationId($notification_id);
            }

            $GLOBALS['Response']->addFeedback(
                Feedback::INFO,
                $GLOBALS['Language']->getText('plugin_svn_admin_notification', 'upd_email_success')
            );
        } catch (CannotCreateMailHeaderException $e) {
            $GLOBALS['Response']->addFeedback(
                Feedback::ERROR,
                $GLOBALS['Language']->getText('plugin_svn_admin_notification', 'upd_email_error')
            );
        }

        $this->redirectOnDisplayNotification($request);
    }

    public function deleteMailingList(HTTPRequest $request) {
        $repository = $this->repository_manager->getById($request->get('repo_id'), $request->getProject());

        $token = $this->generateToken($request->getProject(), $repository);
        $token->check();

        $valid_notification_remove_id = new Valid_Int('notification_remove_id');
        if($request->valid($valid_notification_remove_id)) {
            $notification_remove_id = $request->get('notification_remove_id');
            try {
                $this->mail_notification_manager->removeByNotificationId($notification_remove_id);
                $GLOBALS['Response']->addFeedback(
                    Feedback::INFO,
                    dgettext(
                        'tuleap-svn',
                        'Notification deleted successfully.'
                    )
                );
            } catch (CannotDeleteMailNotificationException $e) {
                $GLOBALS['Response']->addFeedback(Feedback::ERROR, $GLOBALS['Language']->getText('plugin_svn_admin_notification','delete_error'));
            }
        }

        $this->redirectOnDisplayNotification($request);
    }

    public function updateHooksConfig(ServiceSvn $service, HTTPRequest $request) {
        $hook_config = array(
            HookConfig::MANDATORY_REFERENCE => (bool)
                $request->get("pre_commit_must_contain_reference"),
            HookConfig::COMMIT_MESSAGE_CAN_CHANGE => (bool)
                $request->get("allow_commit_message_changes")
        );
        $this->repository_manager->updateHookConfig($request->get('repo_id'), $hook_config);

        return $this->displayHooksConfig($service, $request);
    }

    public function displayHooksConfig(ServiceSvn $service, HTTPRequest $request) {
        $repository = $this->repository_manager->getById($request->get('repo_id'), $request->getProject());
        $hook_config = $this->repository_manager->getHookConfig($repository);


        $token = $this->generateToken($request->getProject(), $repository);
        $title = $GLOBALS['Language']->getText('global', 'Administration');

        $service->renderInPage(
            $request,
            $repository->getName() .' – '. $title,
            'admin/hooks_config',
            new HooksConfigurationPresenter(
                $repository,
                $request->getProject(),
                $token,
                $title,
                $hook_config->getHookConfig(HookConfig::MANDATORY_REFERENCE),
                $hook_config->getHookConfig(HookConfig::COMMIT_MESSAGE_CAN_CHANGE)
            )
        );
    }

    public function displayRepositoryDelete(ServiceSvn $service, HTTPRequest $request)
    {
        $repository = $this->repository_manager->getById($request->get('repo_id'), $request->getProject());
        $title      = $GLOBALS['Language']->getText('global', 'Administration');

        $token = $this->generateTokenDeletion($request->getProject(), $repository);

        $service->renderInPage(
            $request,
            $repository->getName() .' – '. $title,
            'admin/repository_delete',
            new RepositoryDeletePresenter(
                $repository,
                $request->getProject(),
                $title,
                $token
            )
        );
    }

    public function deleteRepository(HTTPRequest $request)
    {
        $project       = $request->getProject();
        $project_id    = $project->getID();
        $repository_id = $request->get('repo_id');

        if ($project_id === null || $repository_id === null || $repository_id === false || $project_id === false) {
            $GLOBALS['Response']->addFeedback('error', 'actions_params_error');
            return false;
        }

        $repository = $this->repository_manager->getById($repository_id, $project);
        if ($repository !== null) {
            $token = $this->generateTokenDeletion($project, $repository);
            $token->check();

            if ($repository->canBeDeleted()) {
                $this->repository_manager->queueRepositoryDeletion($repository, \SystemEventManager::instance());

                $GLOBALS['Response']->addFeedback(
                    'info',
                    $GLOBALS['Language']->getText('plugin_svn', 'actions_delete_process', array($repository->getFullName()))
                );
                $GLOBALS['Response']->addFeedback(
                    'info',
                    $GLOBALS['Language']->getText(
                        'plugin_svn',
                        'actions_delete_backup',
                        array(
                            $repository->getFullName(),
                            $repository->getSystemBackupPath()
                        )
                    )
                );
                $GLOBALS['Response']->addFeedback(
                    'info',
                    $GLOBALS['Language']->getText('plugin_svn', 'feedback_event_delete', array($repository->getFullName()))
                );
            } else {
                $this->redirect($project_id);
                return false;
            }
        } else {
            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_svn', 'actions_repo_not_found'));
        }
        $this->redirect($project_id);
    }

    private function generateTokenDeletion(Project $project, Repository $repository)
    {
        return new CSRFSynchronizerToken(SVN_BASE_URL.'/?'. http_build_query(
            array(
                'group_id' => $project->getID(),
                'repo_id'  => $repository->getId(),
                'action'   => 'delete-repository'
            )
        ));
    }

    private function redirect($project_id)
    {
        $GLOBALS['Response']->redirect(SVN_BASE_URL.'/?'. http_build_query(
            array('group_id' => $project_id)
        ));
    }

    /**
     * @param HTTPRequest $request
     */
    private function redirectOnDisplayNotification(HTTPRequest $request)
    {
        $GLOBALS['Response']->redirect(SVN_BASE_URL . '/?' . http_build_query(
                array(
                    'group_id' => $request->getProject()->getid(),
                    'repo_id' => $request->get('repo_id'),
                    'action' => 'display-mail-notification'
                )));
    }

    /**
     * @return RequestFromAutocompleter
     */
    private function getAutocompleter(Project $project, InvalidEntryInAutocompleterCollection $invalid_entries, $emails)
    {
        $autocompleter = new RequestFromAutocompleter(
            $invalid_entries,
            new Rule_Email(),
            $this->user_manager,
            $this->ugroup_manager,
            $this->user_manager->getCurrentUser(),
            $project,
            $emails
        );
        return $autocompleter;
    }

    private function addFeedbackUsersNotAdded($users_not_added)
    {
        $GLOBALS['Response']->addFeedback(
            Feedback::WARN,
            sprintf(
                dngettext(
                    'tuleap-svn',
                    "User '%s' couldn't be added.",
                    "Users '%s' couldn't be added.",
                    count($users_not_added)
                ),
                implode("' ,'", $users_not_added)
            )
        );
    }

    private function addFeedbackUgroupsNotAdded($ugroups_not_added)
    {
        $GLOBALS['Response']->addFeedback(
            Feedback::WARN,
            sprintf(
                dngettext(
                    'tuleap-svn',
                    "Group '%s' couldn't be added.",
                    "Groups '%s' couldn't be added.",
                    count($ugroups_not_added)
                ),
                implode("' ,'", $ugroups_not_added)
            )
        );
    }

    private function addFeedbackPathError()
    {
        $GLOBALS['Response']->addFeedback(
            Feedback::ERROR,
            $GLOBALS['Language']->getText('plugin_svn_admin_notification', 'update_path_error')
        );
    }
}

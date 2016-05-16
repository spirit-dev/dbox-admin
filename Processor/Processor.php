<?php
/**
 * Copyright (c) 2016. Spirit-Dev
 * Licensed under GPLv3 GNU License - http://www.gnu.org/licenses/gpl-3.0.html
 *    _             _
 *   /_`_  ._._/___/ | _
 * . _//_//// /   /_.'/_'|/
 *    /
 *    
 * Since 2K10 until today
 *  
 * Hex            53 70 69 72 69 74 2d 44 65 76
 *  
 * By             Jean Bordat
 * Twitter        @Ji_Bay_
 * Mail           <bordat.jean@gmail.com>
 *  
 * File           Processor.php
 * Updated the    15/05/16 11:47
 */

namespace SpiritDev\Bundle\DBoxAdminBundle\Processor;

use Doctrine\ORM\Query;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Demand;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Project;
use SpiritDev\Bundle\DBoxUserBundle\Entity\User;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;

/**
 * Class Processor
 * @package SpiritDev\Bundle\DBoxAdminBundle\Processor
 */
class Processor extends ProcessorCore implements ProcessorCoreInterface {

    /**
     * AutoProcess demand, via Type switch
     * @param Demand $demandToProcess
     * @return mixed
     */
    public function autoprocess(Demand $demandToProcess) {
        $resultValues = null;
        // Getting demand types
        $newUserType = $this->em->getRepository('SpiritDevDBoxPortalBundle:Type')->findOneBy(array('canonicalName' => 'new_user'));
        $newProjectType = $this->em->getRepository('SpiritDevDBoxPortalBundle:Type')->findOneBy(array('canonicalName' => 'new_project'));

        // If demand is a new user request
        if ($demandToProcess->getType() == $newUserType) {
            // Process
            $resultValues = $this->processNewUser($demandToProcess);
        }

        // If demand is a new Project Request
        if ($demandToProcess->getType() == $newProjectType) {
            // Process
            $resultValues = $this->processNewProject($demandToProcess);
        }

        return $resultValues;
    }

    /**
     * AutoProcess User
     * @param Demand $demandToProcess
     * @return mixed
     */
    public function processNewUser(Demand $demandToProcess) {
        // Preparing return values
        $returnValues['processor'] = "user";
        $returnValues['demand_id'] = $demandToProcess->getId();

        // Process LDAP registry
        $ldapUser = $this->processLdapUserRegister($demandToProcess);
        if ($ldapUser['created']) {
            $returnValues['ldap_created'] = true;
        } else {
            $returnValues['ldap_created'] = false;
        }

        // Adding user to DB
        $newDBUSer = $this->processDBUserRegister($ldapUser);
        $returnValues['username'] = $newDBUSer->getUsername();
        $returnValues['commonname'] = $newDBUSer->getCommonName();

        // Create gitlab user
        // GitLab API Service
        $gitLabUser = $this->gitlabApi->createUser($newDBUSer);
        if (array_key_exists('id', $gitLabUser)) {
            $returnValues['gitlab_created'] = true;
            $returnValues['gitlab_user_id'] = $gitLabUser['id'];
        }

        // Create redmine user
        $redmineUser = $this->redmineApi->createUser($newDBUSer);
        if (array_key_exists('id', $redmineUser)) {
            $returnValues['redmine_created'] = true;
            $returnValues['redmine_user_id'] = $redmineUser->{"id"};
        } else {
            $returnValues['redmine_created'] = false;
        }

        // Create jenkins user
//            try {
//                $returnValues['jenkins_created'] = $this->jenkinsApi->createUser($newDBUSer);
//            } catch (\Exception $e) {
//                $returnValues['jenkins_created'] = false;
//            }
        $returnValues['jenkins_created'] = 'Deprecated !';


        // Create Sonar user here
        $sonarUser = $this->sonarApi->createUser($newDBUSer);
        if ($sonarUser != null) {
            $returnValues['sonar_created'] = true;
        }

        // If LDAP Processing OK
        $demandProcessed = $this->processDemandUpdate($demandToProcess, $newDBUSer, $gitLabUser, $redmineUser);
        if ($demandProcessed) {
            $this->mailer->processNewUserSendMail($demandProcessed, $ldapUser);
            $returnValues['demand_status'] = $demandProcessed->getStatus()->getCanonicalName();
            $returnValues['mail_sent'] = true;
        }

        return $returnValues;
    }

    /**
     * AutoProcess Project
     * @param Demand $demandToProcess
     * @param bool $flashbag
     * @return mixed
     */
    public function processNewProject(Demand $demandToProcess, $flashbag = true) {
        // Preparing return values
        $returnValues['processor'] = "project";
        $returnValues['demand_id'] = $demandToProcess->getId();

        // Process GitLabAPI project creation
        // Getting related project
        $projectId = $demandToProcess->getContent()["id"];
        $demandProject = $this->em->getRepository('SpiritDevDBoxPortalBundle:Project')->findOneBy(array('id' => $projectId));

        // Process VCS project creation
        if ($demandProject->isVcsManaged()) {
            $returnValues['slot'][] = $this->processVCSProjectCreation($demandProject);
        }

        // Process PM Project creation
        if ($demandProject->isPmManaged()) {
            $returnValues['slot'][] = $this->processPMProjectCreation($demandProject);
        }

        // Process CI Project creation
        if ($demandProject->isCiDevManaged()) {
            $returnValues['slot'][] = $this->processCIProjectCreation($demandProject);
        }

        // Process QA Project creation
        if ($demandProject->isQaDevManaged()) {
            $returnValues['slot'][] = $this->processQAProjectCreation($demandProject);
        }

        // Finalize Process
        $returnValues['slot'][] = $this->processProjectCreationFinalize($demandProject, $demandToProcess, $flashbag);

        // Adding todos
        $returnValues['slot'][] = $this->processProjectCreationTodos($demandProject);

        return $returnValues;
    }

    /**
     * Processor to deactivate user
     * @param User $user
     * @return mixed
     */
    public function deactivateUser(User $user) {
        // Preparing variables
        $returnValues['processor'] = 'deactivate_user';
        $returnValues['username'] = $user->getCommonName() . ' (' . $user->getId() . ')';

        // if user is not locked
        if ($user && !$user->isLocked()) {

            // LDAP deactivate
            $returnValues['ldap_lock'] = $this->ldapDriver->ldapLockAccount($user);

            // VCS deactivate
            $returnValues['vcs_lock'] = $this->gitlabApi->lockUser($user);
            // PM deactivate
            $returnValues['pm_lock'] = $this->redmineApi->lockUser($user);
            // CI deactivate
            $this->addTodo('<b>Jenkins - User managment - Deactivate: </b>' . $user->getUsername());
            // TODO Search for a jenkins internal API user deactivation
            // QA deactivate
            $returnValues['qa_lock'] = $this->sonarApi->deactivateUser($user);

            // DB deactivate
            $user->setLocked(true);
            $this->em->flush();
            $returnValues['db_lock'] = $user->isLocked();

            // Send mail
            $this->mailer->accountUpdate($user, "deactivation");
            $returnValues['mailIssue'] = true;

        } else {
            $returnValues['ldap_lock'] = false;
            $returnValues['vcs_lock'] = false;
            $returnValues['pm_lock'] = false;
            $returnValues['db_lock'] = false;
            $returnValues['mailIssue'] = false;
        }

        // Return values
        return $returnValues;
    }

    /**
     * Processor to activate user
     * @param User $user
     * @return mixed
     */
    public function activateUser(User $user) {
        // Preparing variables
        $returnValues['processor'] = 'activate_user';
        $returnValues['username'] = $user->getCommonName() . ' (' . $user->getId() . ')';

        // If user is locked
        if ($user && $user->isLocked()) {

            // LDAP activate
            $returnValues['ldap_unlock'] = $this->ldapDriver->ldapUnlockAccount($user);

            // VCS activate
            $returnValues['vcs_unlock'] = $this->gitlabApi->unlockUser($user);
            // PM activate
            $returnValues['pm_unlock'] = $this->redmineApi->unlockUser($user);
            // CI activate
            $this->addTodo('<b>Jenkins - User managment - Activate: </b>' . $user->getUsername());
            // TODO Search for a jenkins internal API user deactivation
            // QA activate
            $returnValues['qa_unlock'] = $this->sonarApi->activateUser($user);

            // DB activate
            $user->setLocked(false);
            $this->em->flush();
            $returnValues['db_unlock'] = !$user->isLocked();

            // Send mail
            $this->mailer->accountUpdate($user, "activation");
            $returnValues['mailIssue'] = true;

        } else {
            $returnValues['ldap_unlock'] = false;
            $returnValues['vcs_unlock'] = false;
            $returnValues['pm_unlock'] = false;
            $returnValues['db_unlock'] = false;
            $returnValues['mailIssue'] = false;
        }

        // Return values
        return $returnValues;
    }

    /**
     * Processor to remove user from system
     * @param User $user
     * @return mixed
     */
    public function deleteUser(User $user) {
        // Preparing variables
        $returnValues['processor'] = 'delete_user';
        $returnValues['username'] = $user->getCommonName() . ' (' . $user->getId() . ')';

        // If user
        if ($user) {

            // Removing LDAP User
            try {
                $returnValues['ldapIssue'] = $this->ldapDriver->ldapRemoveUser($user);
            } catch (\Exception $e) {
                $returnValues['ldapIssue'] = false;
            }


            // Remove VCS user
            try {
                $returnValues['vcsIssue'] = $this->gitlabApi->deleteUser($user);
            } catch (\Exception $e) {
                $returnValues['vcsIssue'] = false;
            }
            // Remove PM user
            try {
                $returnValues['pmIssue'] = $this->redmineApi->deleteUser($user);
            } catch (\Exception $e) {
                $returnValues['pmIssue'] = false;
            }
            // Remove CI User
//            try {
//                $returnValues['ciIssue'] = $this->container->get('spirit_dev_dbox_portal_bundle.api.jenkins')->deleteUser($user);
//            } catch (\Exception $e) {
            $returnValues['ciIssue'] = 'Deprecated !';
//            }
            // Remove QA User
            $returnValues['qaIssue'] = $this->sonarApi->deleteUser($user);

            // Remove demands
            $demands = $this->em->getRepository('SpiritDevDBoxPortalBundle:Demand')->findBy(array('applicant' => $user));
            $status = $this->em->getRepository('SpiritDevDBoxPortalBundle:Status')->findOneBy(array('canonicalName' => 'new'));
            foreach ($demands as $demand) {
                $demand->setApplicant(null);
                $demand->setStatus($status);
            }
            // Remove projects
            $projectsOwned = $this->em->getRepository('SpiritDevDBoxPortalBundle:Project')->findBy(array('owner' => $user));
            foreach ($projectsOwned as $project) {
                $project->setOwner(null);
            }
//            $projectMembers = $em->getRepository('SpiritDevDBoxPortalBundle:Project')->findBy(array('teamMembers'=>$user));
//            foreach ($projectMembers as $project) {
//                $project->removeTeamMember($user);
//            }
            // Remove DB User
            $this->em->remove($user);
            // Save changes
            $this->em->flush();
            $returnValues['dbIssue'] = true;

            // Send mail
            $this->mailer->accountUpdate($user, "deletion");
            $returnValues['mailIssue'] = true;

        } else {
            $returnValues['ldapIssue'] = false;
            $returnValues['vcsIssue'] = false;
            $returnValues['pmIssue'] = false;
            $returnValues['ciIssue'] = false;
            $returnValues['qaIssue'] = false;
            $returnValues['dbIssue'] = false;
            $returnValues['mailIssue'] = false;
        }

        // Return values
        return $returnValues;
    }

    /**
     * Processor to remove project from system
     * @param Project $project
     * @return mixed
     */
    public function deleteProject(Project $project) {
        $returnValues['processor'] = 'delete_project';

        if ($project) {

            // Delete VCS Project
            $returnValues['slot'][] = $this->processVCSProjectDeletion($project);

            // Delete PM Project
            $returnValues['slot'][] = $this->processPMProjectDeletion($project);

            // Delete CI Project
            $returnValues['slot'][] = $this->processCIProjectDeletion($project);

            // Delete QA Project
            $returnValues['slot'][] = $this->processQAProjectDeletion($project);

            // Finalize process
            // Normally, in case of deletion, local entity should be removed
            $returnValues['slot'][] = $this->processProjectDeletionFinalize($project, false);

        }

        return $returnValues;
    }

    /**
     * Process a manager for a project
     * @param Project $project
     * @param $manager
     * @return mixed
     */
    public function processProjectManager(Project $project, $manager) {

        $result = "No referenced manager";

        if ($manager == "vcs") {
            $result = $this->processVCSProjectCreation($project);
        } else if ($manager == "pm") {
            $result = $this->processPMProjectCreation($project);
        } else if ($manager == "ci") {
            $result = $this->processCIProjectCreation($project);
        } else if ($manager == "qa") {
            $result = $this->processQAProjectCreation($project);
        }

        $this->processProjectCreationTodos($project);

        return $result;
    }


}
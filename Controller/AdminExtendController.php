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
 * File           AdminExtendController.php
 * Updated the    16/05/16 14:26
 */

namespace SpiritDev\Bundle\DBoxAdminBundle\Controller;

use Doctrine\ORM\Query;
use JavierEguiluz\Bundle\EasyAdminBundle\Controller\AdminController as EasyAdminController;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Demand;

/**
 * Class AdminExtendController
 * @package SpiritDev\Bundle\DBoxAdminBundle\Controller
 */
class AdminExtendController extends EasyAdminController {

    /**
     * AutoProcess Action called via DemandList Action
     */
    public function autoprocessAction() {
        // Getting Demand
        $demandId = $this->request->query->get('id');
        $demandToProcess = $this->em->getRepository('SpiritDevDBoxPortalBundle:Demand')->findOneBy(array('id' => $demandId));

        // Calling processor service
        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->autoprocess($demandToProcess);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }

    /**
     * Activate user action called via UserList Action
     */
    public function activateAction() {
        $userToActivateId = $this->request->query->get('id');
        $userToActivate = $this->em->getRepository('SpiritDevDBoxUserBundle:User')->findOneBy(array('id' => $userToActivateId));

        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->activateUser($userToActivate);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }

    /**
     * Deactivate user action called via UserList Action
     */
    public function deactivateAction() {
        $userToDeactivateId = $this->request->query->get('id');
        $userToDeactivate = $this->em->getRepository('SpiritDevDBoxUserBundle:User')->findOneBy(array('id' => $userToDeactivateId));

        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->deactivateUser($userToDeactivate);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }

    /**
     * Delete user action called via UserList Action
     */
    public function deleteUserAction() {
        $userToDeleteId = $this->request->query->get('id');
        $userToDelete = $this->em->getRepository('SpiritDevDBoxUserBundle:User')->findOneBy(array('id' => $userToDeleteId));

        // Calling processor service
        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->deleteUser($userToDelete);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }

    /**
     * Delete project action called via ProjectList Action
     */
    public function deleteProjectAction() {
        $projectToDeleteId = $this->request->query->get('id');
        $projectToDelete = $this->em->getRepository('SpiritDevDBoxPortalBundle:Project')->findOneBy(array('id' => $projectToDeleteId));

        // Calling processor service
        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->deleteProject($projectToDelete);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }

}

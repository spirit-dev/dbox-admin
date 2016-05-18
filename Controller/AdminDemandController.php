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
 * File           AdminDemandController.php
 * Updated the    18/05/16 11:44
 */

namespace SpiritDev\Bundle\DBoxAdminBundle\Controller;

use Doctrine\ORM\Query;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Demand;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class AdminDemandController
 * @package SpiritDev\Bundle\DBoxAdminBundle\Controller
 */
class AdminDemandController extends Controller {

    /**
     * @Route("/demand_dashboard", name="spirit_dev_dbox_admin_demand_dashboard")
     * @Template()
     */
    public function demandsAction() {

        $demands = $this->getDoctrine()->getRepository('SpiritDevDBoxPortalBundle:Demand')->findAll();

        return array(
            'tab_slot' => 'demands',
            'demands' => $demands
        );
    }

    /**
     * @Route("/demand/change_status/{demandId}", name="spirit_dev_dbox_admin_demand_change_status")
     * @param $demandId
     * @param Request $request
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function demandChangeStatusAction($demandId, Request $request) {
        // Creating form
        $form = $this->createForm('demand_change_status');
        // Handling request with form
        $form->handleRequest($request);
        // If form full and valid
        if ($form->isValid()) {
            // Importing Handler and processing / Updating demand status
            $issue = $this->get('spirit_dev_dbox_portal_bundle.form.handler.demand_change_status')->process($demandId);
            // If well done -> redirect
            if ($issue) {
                $mailer = $this->get('spirit_dev_dbox_portal_bundle.mailer');
                $mailer->changeStatusSendMail($issue, $this->getCurrentUser());
                $this->get('session')->getFlashBag()->set('success', 'flashbag.demand.change_status.success');
            } else {
                $this->get('session')->getFlashBag()->set('error', 'flashbag.demand.change_status.error');
            }
            return $this->redirectToRoute('easyadmin', array(
                'view' => 'list',
                'entity' => 'Demand'
            ));
        }
        // Return form
        return $this->render('SpiritDevDBoxPortalBundle:Demand/Form:demandChangeStatus.html.twig', array(
            'form' => $form->createView(),
            'id' => $demandId
        ));
    }

    /**
     * @return mixed
     * @throws AccessDeniedException
     */
    protected function getCurrentUser() {

        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        if (!is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        return $user;
    }

    /**
     * @Route("/demand/process/{demandId}", name="spirit_dev_dbox_admin_demand_process")
     * @param $demandId
     * @return mixed
     */
    public function demandProcessAction($demandId) {
        // Getting demand to treat
        $demandToProcess = $this->getDoctrine()->getRepository('SpiritDevDBoxPortalBundle:Demand')->findOneBy(array('id' => $demandId));

        // Calling processor service
        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->autoprocess($demandToProcess);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }
}

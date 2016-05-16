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
 * File           ProcessorCoreInterface.php
 * Updated the    15/05/16 11:47
 */

namespace SpiritDev\Bundle\DBoxAdminBundle\Processor;

use SpiritDev\Bundle\DBoxPortalBundle\Entity\Demand;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Project;
use SpiritDev\Bundle\DBoxUserBundle\Entity\User;

/**
 * Interface ProcessorCoreInterface
 * @package SpiritDev\Bundle\DBoxAdminBundle\Processor
 */
interface ProcessorCoreInterface {

    /**
     * AutoProcess demand, via Type switch
     * @param Demand $demandToProcess
     * @return mixed
     */
    public function autoprocess(Demand $demandToProcess);

    /**
     * AutoProcess User
     * @param Demand $demandToProcess
     * @return mixed
     */
    public function processNewUser(Demand $demandToProcess);

    /**
     * AutoProcess Project
     * @param Demand $demandToProcess
     * @return mixed
     */
    public function processNewProject(Demand $demandToProcess);

    /**
     * Processor to deactivate user
     * @param User $user
     * @return mixed
     */
    public function deactivateUser(User $user);

    /**
     * Processor to activate user
     * @param User $user
     * @return mixed
     */
    public function activateUser(User $user);

    /**
     * Processor to remove user from system
     * @param User $user
     * @return mixed
     */
    public function deleteUser(User $user);

    /**
     * Processor to remove project from system
     * @param Project $project
     * @return mixed
     */
    public function deleteProject(Project $project);

    /**
     * Process a manager for a project
     * @param Project $project
     * @param $manager
     * @return mixed
     */
    public function processProjectManager(Project $project, $manager);
}
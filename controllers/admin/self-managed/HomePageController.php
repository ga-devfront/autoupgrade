<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\AutoUpgrade\Controller;

use PrestaShop\Module\AutoUpgrade\Router\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class HomePageController extends AbstractPageController
{
    const CURRENT_PAGE = 'home';
    const CURRENT_ROUTE = Router::HOME_PAGE;

    public function submit(Request $request): JsonResponse
    {
        $routeChoice = $request->request->get('route_choice');

        if ($routeChoice === 'update') {
            return new JsonResponse([
                'next_route' => Router::UPDATE_PAGE_VERSION_CHOICE,
            ]);
        }

        // if is not update is restore
        if ($this->getParams($request)['empty_backup']) {
            return new JsonResponse([
                'error' => 'You can\'t acced this route because you haven\'t backup.',
            ], 401);
        }

        return new JsonResponse([
            'next_route' => Router::RESTORE_PAGE_BACKUP_SELECTION,
        ]);
    }

    /**
     * @return array
     *
     * @throws \Exception
     */
    protected function getParams(Request $request): array
    {
        $backupFinder = $this->upgradeContainer->getBackupFinder();

        return [
            'empty_backup' => empty($backupFinder->getAvailableBackups()),
            'form_route' => Router::HOME_PAGE_FORM,
        ];
    }
}

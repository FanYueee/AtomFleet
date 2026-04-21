<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Support;

final class ModuleLogger
{
    public static function log(string $action, array $params, $request, $response, $processed = null): void
    {
        $sensitiveValues = array();

        foreach (array('password', 'serverpassword', 'serveraccesshash') as $key) {
            if (!empty($params[$key])) {
                $sensitiveValues[] = $params[$key];
            }
        }

        logModuleCall(
            'atomfleetproxmox',
            $action,
            $request,
            $response,
            $processed,
            $sensitiveValues
        );
    }
}

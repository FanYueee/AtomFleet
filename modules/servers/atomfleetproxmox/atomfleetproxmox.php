<?php
declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Autoloader.php';

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;
use AtomFleet\Whmcs\Proxmox\Service\ClusterService;
use AtomFleet\Whmcs\Proxmox\Service\InstanceService;
use AtomFleet\Whmcs\Proxmox\Service\ProvisioningService;
use AtomFleet\Whmcs\Proxmox\Support\ModuleLogger;
use AtomFleet\Whmcs\Proxmox\Support\ModuleParameters;

function atomfleetproxmox_MetaData()
{
    return array(
        'DisplayName' => 'AtomFleet Proxmox',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '8006',
        'AdminSingleSignOnLabel' => 'Open Proxmox',
        'ListAccountsUniqueIdentifierDisplayName' => 'VMID',
        'ListAccountsUniqueIdentifierField' => 'username',
        'ListAccountsProductField' => 'configoption1',
    );
}

function atomfleetproxmox_ConfigOptions()
{
    return array(
        'Guest Type' => array(
            'Type' => 'dropdown',
            'Options' => array(
                'qemu' => 'QEMU Virtual Machine',
                'lxc' => 'LXC Container',
            ),
            'Description' => 'Provision mode for this product',
            'SimpleMode' => true,
        ),
        'Node' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'auto',
            'Description' => 'Target node or "auto"',
            'SimpleMode' => true,
            'Loader' => 'atomfleetproxmox_NodeLoader',
        ),
        'Template Node' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Optional node that stores the template',
            'Loader' => 'atomfleetproxmox_NodeLoader',
        ),
        'QEMU Template VMID' => array(
            'Type' => 'text',
            'Size' => '10',
            'Default' => '',
            'Description' => 'Required for QEMU clone workflows',
            'SimpleMode' => true,
            'Loader' => 'atomfleetproxmox_QemuTemplateLoader',
        ),
        'LXC Template' => array(
            'Type' => 'text',
            'Size' => '55',
            'Default' => '',
            'Description' => 'Example: local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst',
            'Loader' => 'atomfleetproxmox_LxcTemplateLoader',
        ),
        'Clone Mode' => array(
            'Type' => 'dropdown',
            'Options' => array(
                'full' => 'Full Clone',
                'linked' => 'Linked Clone',
            ),
            'Description' => 'Used by QEMU clone operations',
        ),
        'Storage' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'local-lvm',
            'Description' => 'Disk or rootfs target storage',
            'SimpleMode' => true,
            'Loader' => 'atomfleetproxmox_StorageLoader',
        ),
        'Network Bridge' => array(
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'vmbr0',
            'Description' => 'Primary bridge or SDN network',
            'SimpleMode' => true,
            'Loader' => 'atomfleetproxmox_BridgeLoader',
        ),
        'NIC Model' => array(
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'virtio',
            'Description' => 'QEMU NIC model',
        ),
        'CPU Cores' => array(
            'Type' => 'text',
            'Size' => '10',
            'Default' => '2',
            'Description' => 'vCPU count',
            'SimpleMode' => true,
        ),
        'Memory (MB)' => array(
            'Type' => 'text',
            'Size' => '10',
            'Default' => '2048',
            'Description' => 'Assigned memory in MB',
            'SimpleMode' => true,
        ),
        'Disk Size (GB)' => array(
            'Type' => 'text',
            'Size' => '10',
            'Default' => '20',
            'Description' => 'Used for LXC rootfs and QEMU resize',
            'SimpleMode' => true,
        ),
        'Cloud-Init User' => array(
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'root',
            'Description' => 'Applied when the QEMU template supports cloud-init',
        ),
        'Proxmox Pool' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Optional Proxmox resource pool',
        ),
        'Nameserver' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Optional guest nameserver',
        ),
        'Search Domain' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Optional guest search domain',
        ),
        'IPv4 Assignment Mode' => array(
            'Type' => 'dropdown',
            'Options' => array(
                'pool' => 'Auto Allocate from WHMCS IP Pool',
                'manual' => 'Use Manual Custom Field / Dedicated IP / DHCP',
            ),
            'Default' => 'pool',
            'Description' => 'QEMU uses cloud-init. LXC applies the allocated address to net0.',
        ),
        'IPv4 Pool' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Optional logical pool name. Leave blank to use any free IP on this WHMCS server.',
        ),
        'Start After Provision' => array(
            'Type' => 'yesno',
            'Description' => 'Boot the guest after create',
        ),
        'Start On Boot' => array(
            'Type' => 'yesno',
            'Description' => 'Enable autostart inside Proxmox',
        ),
        'Release IP On Terminate' => array(
            'Type' => 'yesno',
            'Description' => 'Return pool-allocated IPv4 addresses to the WHMCS IP pool on termination',
        ),
    );
}

function atomfleetproxmox_TestConnection(array $params)
{
    try {
        (new InstanceService($params))->testConnection();

        return array(
            'success' => true,
            'error' => '',
        );
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $params, $e->getMessage(), $e->getTraceAsString());

        return array(
            'success' => false,
            'error' => $e->getMessage(),
        );
    }
}

function atomfleetproxmox_ListAccounts(array $params)
{
    try {
        return array(
            'success' => true,
            'accounts' => (new ClusterService($params))->listWhmcsSyncAccounts(),
        );
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $params, $e->getMessage(), $e->getTraceAsString());

        return array(
            'success' => false,
            'error' => $e->getMessage(),
            'accounts' => array(),
        );
    }
}

function atomfleetproxmox_AdminLink(array $params)
{
    $host = !empty($params['serverhostname']) ? $params['serverhostname'] : ($params['serverip'] ?? '');
    $port = !empty($params['serverport']) ? (int) $params['serverport'] : 8006;
    $scheme = !empty($params['serversecure']) ? 'https' : 'http';

    if (!$host) {
        return '';
    }

    $href = sprintf('%s://%s:%d/', $scheme, $host, $port);

    return '<a class="btn btn-default btn-sm" href="'
        . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
        . '" target="_blank" rel="noopener">Open Proxmox</a>';
}

function atomfleetproxmox_LoginLink(array $params)
{
    return atomfleetproxmox_AdminLink($params);
}

function atomfleetproxmox_AdminSingleSignOn(array $params)
{
    try {
        return array(
            'success' => true,
            'redirectTo' => (new InstanceService($params))->getPanelUrl(),
        );
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $params, $e->getMessage(), $e->getTraceAsString());

        return array(
            'success' => false,
            'errorMsg' => $e->getMessage(),
        );
    }
}

function atomfleetproxmox_CreateAccount(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new ProvisioningService($params))->createAccount();

        return 'success';
    });
}

function atomfleetproxmox_SuspendAccount(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new ProvisioningService($params))->suspendAccount();

        return 'success';
    });
}

function atomfleetproxmox_UnsuspendAccount(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new ProvisioningService($params))->unsuspendAccount();

        return 'success';
    });
}

function atomfleetproxmox_TerminateAccount(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new ProvisioningService($params))->terminateAccount();

        return 'success';
    });
}

function atomfleetproxmox_ChangePackage(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new ProvisioningService($params))->changePackage();

        return 'success';
    });
}

function atomfleetproxmox_ChangePassword(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new ProvisioningService($params))->changePassword();

        return 'success';
    });
}

function atomfleetproxmox_AdminCustomButtonArray()
{
    return array(
        'Start Guest' => 'adminStartGuest',
        'Shutdown Guest' => 'adminShutdownGuest',
        'Reboot Guest' => 'adminRebootGuest',
        'Force Stop' => 'adminStopGuest',
        'Refresh Link' => 'adminRefreshGuestLink',
        'Unlink Guest' => 'adminUnlinkGuest',
    );
}

function atomfleetproxmox_adminStartGuest(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new InstanceService($params))->performPowerAction('start');

        return 'success';
    });
}

function atomfleetproxmox_adminShutdownGuest(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new InstanceService($params))->performPowerAction('shutdown');

        return 'success';
    });
}

function atomfleetproxmox_adminRebootGuest(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new InstanceService($params))->performPowerAction('reboot');

        return 'success';
    });
}

function atomfleetproxmox_adminStopGuest(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new InstanceService($params))->performPowerAction('stop');

        return 'success';
    });
}

function atomfleetproxmox_adminRefreshGuestLink(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new InstanceService($params))->refreshLink();

        return 'success';
    });
}

function atomfleetproxmox_adminUnlinkGuest(array $params)
{
    return atomfleetproxmox_wrap(__FUNCTION__, $params, static function () use ($params) {
        (new InstanceService($params))->unlinkGuest();

        return 'success';
    });
}

function atomfleetproxmox_ClientAreaAllowedFunctions()
{
    return array('api');
}

function atomfleetproxmox_api(array $params)
{
    atomfleetproxmox_handleApi($params);

    return '';
}

function atomfleetproxmox_AdminServicesTabFields(array $params)
{
    try {
        $moduleParams = new ModuleParameters($params);
        $dashboard = (new InstanceService($params))->getDashboardPayload();
        $instance = isset($dashboard['instance']) && is_array($dashboard['instance']) ? $dashboard['instance'] : array();
        $meta = isset($dashboard['meta']) && is_array($dashboard['meta']) ? $dashboard['meta'] : array();
        $vmidValue = isset($instance['vmid']) && $instance['vmid'] !== null ? (string) $instance['vmid'] : '';
        $nodeValue = isset($instance['node']) ? (string) $instance['node'] : '';
        $guestTypeValue = isset($instance['guestType']) ? (string) $instance['guestType'] : '';
        $nameValue = isset($instance['name']) ? (string) $instance['name'] : '';
        $warning = !empty($meta['warning']) ? (string) $meta['warning'] : '';
        $flashNotice = atomfleetproxmox_admin_flash_consume();
        $dedicatedIp = trim((string) $moduleParams->serviceProperty('Dedicated IP', ''));
        $allocationSource = trim((string) $moduleParams->serviceProperty('IP Allocation Source', ''));
        $ipPool = trim((string) $moduleParams->serviceProperty('IP Pool', ''));
        $panelHtml = '<a href="'
            . atomfleetproxmox_html((string) ($meta['panelUrl'] ?? ''))
            . '" target="_blank" rel="noopener">Open Proxmox</a>';

        if ($warning !== '') {
            $panelHtml .= '<div style="margin-top:8px;color:#b94a48;">' . atomfleetproxmox_html($warning) . '</div>';
        }

        if ($flashNotice !== '') {
            $panelHtml .= '<div style="margin-top:8px;color:#3c763d;">' . atomfleetproxmox_html($flashNotice) . '</div>';
        }

        return array(
            'Proxmox Panel' => $panelHtml,
            'Linked VMID' => '<input type="text" name="atomfleetproxmox_vmid" value="'
                . atomfleetproxmox_html($vmidValue)
                . '" size="12" placeholder="100" />',
            'Linked Node' => '<input type="text" name="atomfleetproxmox_node" value="'
                . atomfleetproxmox_html($nodeValue)
                . '" size="18" placeholder="node1" />',
            'Linked Guest Type' => atomfleetproxmox_admin_guest_type_select($guestTypeValue),
            'Guest Name' => '<input type="text" name="atomfleetproxmox_name" value="'
                . atomfleetproxmox_html($nameValue)
                . '" size="28" placeholder="vm-100" />',
            'Import / Repair' => '<div style="max-width:560px;color:#666;">'
                . 'Paste an existing Proxmox VMID here and click <strong>Save Changes</strong> to bind this WHMCS service to an existing guest. '
                . 'If Node or Guest Type is left blank, the module will try to auto-discover them from Proxmox.'
                . '</div>',
            'Status' => atomfleetproxmox_array_get($dashboard, array('stats', 'statusLabel'), 'Unknown'),
            'Primary IPv4' => $dedicatedIp !== '' ? atomfleetproxmox_html($dedicatedIp) : 'n/a',
            'IP Source' => $allocationSource !== '' ? atomfleetproxmox_html($allocationSource) : 'n/a',
            'IP Pool' => $ipPool !== '' ? atomfleetproxmox_html($ipPool) : 'n/a',
            'CPU' => atomfleetproxmox_array_get($dashboard, array('stats', 'cpu', 'display'), 'n/a'),
            'Memory' => atomfleetproxmox_array_get($dashboard, array('stats', 'memory', 'display'), 'n/a'),
            'Disk' => atomfleetproxmox_array_get($dashboard, array('stats', 'disk', 'display'), 'n/a'),
            'Uptime' => atomfleetproxmox_array_get($dashboard, array('stats', 'uptimeLabel'), 'n/a'),
            'Updated' => atomfleetproxmox_array_get($dashboard, array('meta', 'updatedAt'), 'n/a'),
        );
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $params, $e->getMessage(), $e->getTraceAsString());

        return array();
    }
}

function atomfleetproxmox_AdminServicesTabFieldsSave(array $params)
{
    $vmid = trim((string) ($_REQUEST['atomfleetproxmox_vmid'] ?? ''));
    $node = trim((string) ($_REQUEST['atomfleetproxmox_node'] ?? ''));
    $guestType = trim((string) ($_REQUEST['atomfleetproxmox_guesttype'] ?? ''));
    $name = trim((string) ($_REQUEST['atomfleetproxmox_name'] ?? ''));

    try {
        $service = new InstanceService($params);

        if ($vmid === '' && $node === '' && $guestType === '' && $name === '') {
            $service->unlinkGuest();
            atomfleetproxmox_admin_flash_set('Proxmox guest link was cleared.');

            return;
        }

        $service->linkExistingGuest((int) $vmid, $node, $guestType, $name);
        atomfleetproxmox_admin_flash_set('Proxmox guest link was updated.');
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $_REQUEST, $e->getMessage(), $e->getTraceAsString());
        atomfleetproxmox_admin_flash_set('Unable to update link: ' . $e->getMessage());
    }
}

function atomfleetproxmox_ClientArea(array $params)
{
    try {
        $dashboard = atomfleetproxmox_filter_client_dashboard((new InstanceService($params))->getDashboardPayload());
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $moduleVersion = atomfleetproxmox_module_version();

        return array(
            'templatefile' => 'clientarea',
            'vars' => array(
                'moduleVersion' => $moduleVersion,
                'moduleCssUrl' => 'modules/servers/atomfleetproxmox/assets/css/client.css?v=' . rawurlencode($moduleVersion),
                'moduleJsUrl' => 'modules/servers/atomfleetproxmox/assets/js/client-dashboard.js?v=' . rawurlencode($moduleVersion),
                'apiUrl' => 'clientarea.php?action=productdetails&id=' . $serviceId . '&modop=custom&a=api',
                'dashboard' => $dashboard,
                'dashboardJson' => json_encode($dashboard, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
                'bootstrapId' => 'atomfleet-proxmox-bootstrap-' . $serviceId,
                'serviceId' => $serviceId,
            ),
        );
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $params, $e->getMessage(), $e->getTraceAsString());

        return array(
            'templatefile' => 'error',
            'vars' => array(
                'errorMessage' => $e->getMessage(),
            ),
        );
    }
}

function atomfleetproxmox_handleApi(array $params)
{
    try {
        $service = new InstanceService($params);
        $endpoint = strtolower((string) ($_REQUEST['endpoint'] ?? 'dashboard'));

        switch ($endpoint) {
            case 'dashboard':
                $payload = atomfleetproxmox_filter_client_dashboard($service->getDashboardPayload());
                break;

            case 'power':
                if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                    throw new ModuleException('Power actions require POST.');
                }

                $action = strtolower((string) ($_REQUEST['powerAction'] ?? ''));
                $payload = $service->powerAction($action);
                if (isset($payload['dashboard']) && is_array($payload['dashboard'])) {
                    $payload['dashboard'] = atomfleetproxmox_filter_client_dashboard($payload['dashboard']);
                }
                break;

            default:
                throw new ModuleException('Unknown AtomFleet Proxmox API endpoint: ' . $endpoint);
        }

        atomfleetproxmox_json_response($payload, 200);
    } catch (\Throwable $e) {
        atomfleetproxmox_log(__FUNCTION__, $params, $_REQUEST, $e->getMessage(), $e->getTraceAsString());
        atomfleetproxmox_json_response(array('error' => $e->getMessage()), 400);
    }
}

function atomfleetproxmox_wrap(string $functionName, array $params, callable $callback)
{
    try {
        return $callback();
    } catch (\Throwable $e) {
        atomfleetproxmox_log($functionName, $params, $params, $e->getMessage(), $e->getTraceAsString());

        return $e->getMessage();
    }
}

function atomfleetproxmox_json_response(array $payload, int $statusCode)
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    exit;
}

function atomfleetproxmox_filter_client_dashboard(array $dashboard)
{
    if (isset($dashboard['meta']) && is_array($dashboard['meta'])) {
        unset($dashboard['meta']['panelUrl']);
    }

    return $dashboard;
}

function atomfleetproxmox_log(string $action, array $params, $request, $response, $processed = null)
{
    ModuleLogger::log($action, $params, $request, $response, $processed);
}

function atomfleetproxmox_module_version()
{
    return '0.3.0';
}

function atomfleetproxmox_array_get(array $source, array $segments, $default = null)
{
    $cursor = $source;

    foreach ($segments as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return $default;
        }

        $cursor = $cursor[$segment];
    }

    return $cursor;
}

function atomfleetproxmox_NodeLoader(array $params)
{
    $service = atomfleetproxmox_cluster_service_from_params($params);

    if (!$service) {
        return array('auto' => 'Auto-select first online node');
    }

    return $service->getNodeOptions();
}

function atomfleetproxmox_QemuTemplateLoader(array $params)
{
    $service = atomfleetproxmox_cluster_service_from_params($params);

    if (!$service) {
        return array();
    }

    return $service->getQemuTemplateOptions((string) ($params['configoption2'] ?? ''));
}

function atomfleetproxmox_LxcTemplateLoader(array $params)
{
    $service = atomfleetproxmox_cluster_service_from_params($params);

    if (!$service) {
        return array();
    }

    return $service->getLxcTemplateOptions((string) ($params['configoption2'] ?? ''));
}

function atomfleetproxmox_StorageLoader(array $params)
{
    $service = atomfleetproxmox_cluster_service_from_params($params);

    if (!$service) {
        return array('local-lvm' => 'local-lvm');
    }

    return $service->getStorageOptions((string) ($params['configoption2'] ?? ''));
}

function atomfleetproxmox_BridgeLoader(array $params)
{
    $service = atomfleetproxmox_cluster_service_from_params($params);

    if (!$service) {
        return array('vmbr0' => 'vmbr0');
    }

    return $service->getBridgeOptions((string) ($params['configoption2'] ?? ''));
}

function atomfleetproxmox_cluster_service_from_params(array $params)
{
    $host = !empty($params['serverhostname']) ? $params['serverhostname'] : ($params['serverip'] ?? '');
    $username = $params['serverusername'] ?? '';
    $password = $params['serverpassword'] ?? '';

    if ($host === '' || $username === '' || $password === '') {
        return null;
    }

    try {
        return new ClusterService($params);
    } catch (\Throwable $e) {
        return null;
    }
}

function atomfleetproxmox_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function atomfleetproxmox_admin_guest_type_select(string $selected): string
{
    $options = array(
        '' => 'Auto-detect',
        'qemu' => 'QEMU',
        'lxc' => 'LXC',
    );
    $html = '<select name="atomfleetproxmox_guesttype">';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . atomfleetproxmox_html($value) . '"';

        if ($value === $selected) {
            $html .= ' selected="selected"';
        }

        $html .= '>' . atomfleetproxmox_html($label) . '</option>';
    }

    $html .= '</select>';

    return $html;
}

function atomfleetproxmox_admin_flash_set(string $message): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['atomfleetproxmox_admin_flash'] = $message;
    }
}

function atomfleetproxmox_admin_flash_consume(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['atomfleetproxmox_admin_flash'])) {
        return '';
    }

    $message = (string) $_SESSION['atomfleetproxmox_admin_flash'];
    unset($_SESSION['atomfleetproxmox_admin_flash']);

    return $message;
}

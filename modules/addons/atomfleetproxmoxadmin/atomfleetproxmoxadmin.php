<?php
declare(strict_types=1);

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;
use AtomFleet\Whmcs\Proxmox\Repository\IpPoolRepository;
use AtomFleet\Whmcs\Proxmox\Service\ClusterService;
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once dirname(__DIR__, 2) . '/servers/atomfleetproxmox/lib/Autoloader.php';

function atomfleetproxmoxadmin_config()
{
    return array(
        'name' => 'AtomFleet Proxmox Admin',
        'description' => 'Admin dashboard and IPv4 pool manager for AtomFleet Proxmox servers. Use Proxmox for deep infrastructure control and WHMCS for customer/service management.',
        'author' => 'AtomFleet',
        'version' => '0.3.0',
        'language' => 'english',
        'fields' => array(),
    );
}

function atomfleetproxmoxadmin_activate()
{
    (new IpPoolRepository())->ensureSchema();

    return array(
        'status' => 'success',
        'description' => 'AtomFleet Proxmox Admin was activated. The WHMCS IP pool table is ready.',
    );
}

function atomfleetproxmoxadmin_deactivate()
{
    return array(
        'status' => 'success',
        'description' => 'AtomFleet Proxmox Admin was deactivated.',
    );
}

function atomfleetproxmoxadmin_output($vars)
{
    $repository = new IpPoolRepository();
    $repository->ensureSchema();
    $servers = Capsule::table('tblservers')
        ->where('type', '=', 'atomfleetproxmox')
        ->where('disabled', '=', 0)
        ->orderBy('id', 'asc')
        ->get();

    if ($servers->isEmpty()) {
        echo atomfleetproxmoxadmin_styles();
        echo '<div class="afpa-shell">';
        echo '<div class="afpa-head">';
        echo '<div>';
        echo '<h2>AtomFleet Proxmox Admin</h2>';
        echo '<p>WHMCS stays focused on customers, billing, service registry, and IPv4 allocation. Proxmox stays focused on infrastructure control.</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="alert alert-warning">No enabled WHMCS servers found for module type <code>atomfleetproxmox</code>.</div>';
        echo '</div>';

        return;
    }

    $selectedServerId = (int) ($_REQUEST['server_id'] ?? $servers->first()->id);
    $selectedServer = null;

    foreach ($servers as $server) {
        if ((int) $server->id === $selectedServerId) {
            $selectedServer = $server;
            break;
        }
    }

    if (!$selectedServer) {
        $selectedServer = $servers->first();
        $selectedServerId = (int) $selectedServer->id;
    }

    atomfleetproxmoxadmin_handle_pool_post($vars, $repository, $selectedServerId);
    $flash = atomfleetproxmoxadmin_flash_consume();
    $poolEntries = $repository->listEntries($selectedServerId);
    $allocatedCount = 0;

    foreach ($poolEntries as $entry) {
        if (!empty($entry['allocated'])) {
            $allocatedCount++;
        }
    }

    $freeCount = count($poolEntries) - $allocatedCount;

    echo atomfleetproxmoxadmin_styles();
    echo '<div class="afpa-shell">';
    echo '<div class="afpa-head">';
    echo '<div>';
    echo '<h2>AtomFleet Proxmox Admin</h2>';
    echo '<p>WHMCS stays focused on customers, billing, service registry, and IPv4 allocation. Proxmox stays focused on infrastructure control.</p>';
    echo '</div>';
    echo '<div class="afpa-hint">Bulk import existing guests with WHMCS Server Sync. Use the service admin tab to repair or relink individual services.</div>';
    echo '</div>';

    if ($flash !== '') {
        echo '<div class="alert alert-info">' . atomfleetproxmoxadmin_html($flash) . '</div>';
    }

    echo '<form method="get" action="' . atomfleetproxmoxadmin_html($vars['modulelink']) . '" class="afpa-toolbar">';
    echo '<input type="hidden" name="module" value="atomfleetproxmoxadmin" />';
    echo '<label for="afpa-server-id">WHMCS Server</label>';
    echo '<select id="afpa-server-id" name="server_id">';

    foreach ($servers as $server) {
        $label = !empty($server->name) ? $server->name : ('Server #' . $server->id);
        $target = trim((string) ($server->hostname ?: $server->ipaddress));
        echo '<option value="' . (int) $server->id . '"';

        if ((int) $server->id === $selectedServerId) {
            echo ' selected="selected"';
        }

        echo '>' . atomfleetproxmoxadmin_html($label . ' - ' . $target) . '</option>';
    }

    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Load Cluster</button>';
    echo '</form>';

    try {
        $params = atomfleetproxmoxadmin_build_server_params($selectedServer);
        $cluster = new ClusterService($params);
        $overview = $cluster->getAdminOverview();
        $whmcsServices = (int) Capsule::table('tblhosting')->where('server', '=', $selectedServerId)->count();

        echo '<div class="afpa-cards">';
        echo atomfleetproxmoxadmin_card('Proxmox Version', $overview['version'], $overview['release']);
        echo atomfleetproxmoxadmin_card('Nodes', (string) $overview['summary']['nodes'], 'Detected cluster nodes');
        echo atomfleetproxmoxadmin_card('Guests', (string) $overview['summary']['guests'], $overview['summary']['running'] . ' running / ' . $overview['summary']['stopped'] . ' stopped');
        echo atomfleetproxmoxadmin_card('WHMCS Services', (string) $whmcsServices, 'Services assigned to this WHMCS server');
        echo atomfleetproxmoxadmin_card('IPv4 Pool', (string) count($poolEntries), $freeCount . ' free / ' . $allocatedCount . ' allocated');
        echo '</div>';

        echo '<div class="afpa-actions">';
        echo '<a class="btn btn-default" href="' . atomfleetproxmoxadmin_html($overview['panelUrl']) . '" target="_blank" rel="noopener">Open Proxmox</a>';
        echo '</div>';

        echo '<div class="afpa-grid">';
        echo '<section class="afpa-panel">';
        echo '<h3>Nodes</h3>';
        echo '<table class="afpa-table">';
        echo '<thead><tr><th>Node</th><th>Status</th><th>CPU</th><th>Memory</th></tr></thead><tbody>';

        foreach ($overview['nodes'] as $node) {
            echo '<tr>';
            echo '<td>' . atomfleetproxmoxadmin_html($node['name']) . '</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($node['statusLabel']) . '</td>';
            echo '<td>' . number_format((float) $node['cpuPercent'], 1) . '%</td>';
            echo '<td>' . number_format((float) $node['memoryPercent'], 1) . '%</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';

        echo '<section class="afpa-panel">';
        echo '<h3>Cluster Guests</h3>';
        echo '<div class="afpa-scroll">';
        echo '<table class="afpa-table">';
        echo '<thead><tr><th>VMID</th><th>Name</th><th>Type</th><th>Node</th><th>Status</th><th>CPU</th><th>Memory</th><th>Uptime</th></tr></thead><tbody>';

        foreach (array_slice($overview['guests'], 0, 120) as $guest) {
            echo '<tr>';
            echo '<td>' . (int) $guest['vmid'] . '</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($guest['name']) . '</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($guest['guestTypeLabel']) . '</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($guest['node']) . '</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($guest['statusLabel']) . '</td>';
            echo '<td>' . number_format((float) $guest['cpuPercent'], 1) . '%</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($guest['memoryDisplay']) . '</td>';
            echo '<td>' . atomfleetproxmoxadmin_html($guest['uptimeLabel']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
        echo '</div>';

        echo '<section class="afpa-panel" id="ip-pool">';
        echo '<div class="afpa-panel-head-row">';
        echo '<div>';
        echo '<h3>IPv4 Pool</h3>';
        echo '<p>Provisioned QEMU guests receive their IPv4 through cloud-init. LXC guests receive the same address on <code>net0</code>.</p>';
        echo '</div>';
        echo '<div class="afpa-pool-summary">' . $freeCount . ' free / ' . $allocatedCount . ' allocated</div>';
        echo '</div>';

        echo '<form method="post" action="' . atomfleetproxmoxadmin_html($vars['modulelink']) . '" class="afpa-import-form">';
        echo '<input type="hidden" name="module" value="atomfleetproxmoxadmin" />';
        echo '<input type="hidden" name="server_id" value="' . (int) $selectedServerId . '" />';
        echo '<input type="hidden" name="afpa_action" value="ip_import" />';
        echo '<div class="afpa-form-grid">';
        echo '<label>Pool Name<input type="text" name="default_pool_name" value="" placeholder="public-v4" /></label>';
        echo '<label>Prefix<input type="text" name="default_prefix" value="24" placeholder="24" /></label>';
        echo '<label>Gateway<input type="text" name="default_gateway" value="" placeholder="203.0.113.1" /></label>';
        echo '<label>Bridge<input type="text" name="default_bridge" value="" placeholder="vmbr0" /></label>';
        echo '</div>';
        echo '<label class="afpa-textarea-label">IPv4 Addresses</label>';
        echo '<textarea name="ip_addresses" rows="6" placeholder="203.0.113.10&#10;203.0.113.11&#10;10.0.6.0/24,10.0.6.1,public-v4,vmbr0"></textarea>';
        echo '<div class="afpa-form-note">Each line accepts <code>IP</code>, <code>host/prefix</code>, or <code>network/prefix,gateway,pool,bridge</code>. Network CIDRs expand into usable IPv4 hosts and skip the gateway when it matches a host inside that subnet.</div>';
        echo '<button type="submit" class="btn btn-primary">Import IP Addresses</button>';
        echo '</form>';

        echo '<div class="afpa-scroll">';
        echo '<table class="afpa-table">';
        echo '<thead><tr><th>IPv4</th><th>Pool</th><th>Bridge</th><th>Gateway</th><th>Status</th><th>Service</th><th>Actions</th></tr></thead><tbody>';

        if (empty($poolEntries)) {
            echo '<tr><td colspan="7">No IPv4 entries exist for this WHMCS server yet.</td></tr>';
        } else {
            foreach ($poolEntries as $entry) {
                echo '<tr>';
                echo '<td>' . atomfleetproxmoxadmin_html($entry['address'] . '/' . $entry['prefix']) . '</td>';
                echo '<td>' . atomfleetproxmoxadmin_html($entry['pool_name'] !== '' ? $entry['pool_name'] : '--') . '</td>';
                echo '<td>' . atomfleetproxmoxadmin_html($entry['bridge'] !== '' ? $entry['bridge'] : '--') . '</td>';
                echo '<td>' . atomfleetproxmoxadmin_html($entry['gateway'] !== '' ? $entry['gateway'] : '--') . '</td>';
                echo '<td>' . (!empty($entry['allocated']) ? 'Allocated' : 'Free') . '</td>';
                echo '<td>' . atomfleetproxmoxadmin_html($entry['serviceLabel'] !== '' ? $entry['serviceLabel'] : '--') . '</td>';
                echo '<td class="afpa-row-actions">';

                if (!empty($entry['allocated'])) {
                    echo '<form method="post" action="' . atomfleetproxmoxadmin_html($vars['modulelink']) . '">';
                    echo '<input type="hidden" name="module" value="atomfleetproxmoxadmin" />';
                    echo '<input type="hidden" name="server_id" value="' . (int) $selectedServerId . '" />';
                    echo '<input type="hidden" name="afpa_action" value="ip_release" />';
                    echo '<input type="hidden" name="entry_id" value="' . (int) $entry['id'] . '" />';
                    echo '<button type="submit" class="btn btn-default btn-xs">Release</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" action="' . atomfleetproxmoxadmin_html($vars['modulelink']) . '">';
                    echo '<input type="hidden" name="module" value="atomfleetproxmoxadmin" />';
                    echo '<input type="hidden" name="server_id" value="' . (int) $selectedServerId . '" />';
                    echo '<input type="hidden" name="afpa_action" value="ip_delete" />';
                    echo '<input type="hidden" name="entry_id" value="' . (int) $entry['id'] . '" />';
                    echo '<button type="submit" class="btn btn-danger btn-xs">Delete</button>';
                    echo '</form>';
                }

                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Unable to load Proxmox data: '
            . atomfleetproxmoxadmin_html($e->getMessage())
            . '</div>';
    }

    echo '</div>';
}

function atomfleetproxmoxadmin_build_server_params($server)
{
    $decrypted = localAPI('DecryptPassword', array('password2' => $server->password));
    $password = '';

    if (is_array($decrypted) && !empty($decrypted['password'])) {
        $password = (string) $decrypted['password'];
    }

    if ($password === '') {
        throw new RuntimeException('Could not decrypt the Proxmox server password for WHMCS server #' . $server->id . '.');
    }

    return array(
        'serverhostname' => !empty($server->hostname) ? (string) $server->hostname : '',
        'serverip' => !empty($server->ipaddress) ? (string) $server->ipaddress : '',
        'serverusername' => !empty($server->username) ? (string) $server->username : '',
        'serverpassword' => $password,
        'serversecure' => isset($server->secure) ? (bool) $server->secure : true,
        'serverport' => !empty($server->port) ? (int) $server->port : 8006,
        'configoptions' => array(),
    );
}

function atomfleetproxmoxadmin_handle_pool_post(array $vars, IpPoolRepository $repository, int $serverId): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }

    $action = trim((string) ($_POST['afpa_action'] ?? ''));

    if ($action === '') {
        return;
    }

    try {
        switch ($action) {
            case 'ip_import':
                $entries = atomfleetproxmoxadmin_parse_ip_import($_POST);
                $summary = $repository->importEntries($serverId, $entries);
                atomfleetproxmoxadmin_flash_set(
                    'IP import completed. Added '
                    . $summary['added']
                    . ', updated '
                    . $summary['updated']
                    . ', skipped '
                    . $summary['skipped']
                    . ', invalid '
                    . $summary['invalid']
                    . '.'
                );
                break;

            case 'ip_release':
                $repository->releaseEntry($serverId, (int) ($_POST['entry_id'] ?? 0));
                atomfleetproxmoxadmin_flash_set('IP pool entry was released.');
                break;

            case 'ip_delete':
                $repository->deleteEntry($serverId, (int) ($_POST['entry_id'] ?? 0));
                atomfleetproxmoxadmin_flash_set('IP pool entry was deleted.');
                break;
        }
    } catch (\Throwable $e) {
        atomfleetproxmoxadmin_flash_set('IP pool update failed: ' . $e->getMessage());
    }

    $target = $vars['modulelink'] . '&server_id=' . $serverId . '#ip-pool';

    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    }
}

function atomfleetproxmoxadmin_parse_ip_import(array $input): array
{
    $defaultPool = trim((string) ($input['default_pool_name'] ?? ''));
    $defaultPrefix = (int) ($input['default_prefix'] ?? 0);
    $defaultGateway = trim((string) ($input['default_gateway'] ?? ''));
    $defaultBridge = trim((string) ($input['default_bridge'] ?? ''));
    $lines = preg_split('/\r\n|\r|\n/', (string) ($input['ip_addresses'] ?? ''));
    $entries = array();

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '') {
            continue;
        }

        $segments = array_map('trim', explode(',', $line));
        $addressPart = (string) ($segments[0] ?? '');
        $gateway = trim((string) ($segments[1] ?? $defaultGateway));
        $poolName = trim((string) ($segments[2] ?? $defaultPool));
        $bridge = trim((string) ($segments[3] ?? $defaultBridge));
        $expanded = atomfleetproxmoxadmin_expand_ip_import_targets($addressPart, $defaultPrefix, $gateway);

        foreach ($expanded['addresses'] as $address) {
            $entries[] = array(
                'address' => $address,
                'prefix' => $expanded['prefix'],
                'gateway' => $gateway,
                'pool_name' => $poolName,
                'bridge' => $bridge,
            );
        }
    }

    return $entries;
}

function atomfleetproxmoxadmin_expand_ip_import_targets(string $addressPart, int $defaultPrefix, string $gateway): array
{
    $addressPart = trim($addressPart);
    $address = $addressPart;
    $prefix = $defaultPrefix;
    $hasSubnet = false;

    if (strpos($addressPart, '/') !== false) {
        list($address, $inlineMask) = array_pad(explode('/', $addressPart, 2), 2, '');
        $address = trim($address);
        $inlineMask = trim($inlineMask);
        $hasSubnet = $inlineMask !== '';

        if ($hasSubnet) {
            $prefix = atomfleetproxmoxadmin_parse_prefix($inlineMask);
        }
    }

    if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return array(
            'addresses' => array($address),
            'prefix' => $prefix,
        );
    }

    if (!$hasSubnet || $prefix < 1 || $prefix > 32) {
        return array(
            'addresses' => array($address),
            'prefix' => $prefix,
        );
    }

    $networkLong = atomfleetproxmoxadmin_network_long($address, $prefix);
    $addressLong = ip2long($address);

    if ($addressLong === false || $networkLong === false || $addressLong !== $networkLong) {
        return array(
            'addresses' => array($address),
            'prefix' => $prefix,
        );
    }

    return array(
        'addresses' => atomfleetproxmoxadmin_expand_network_hosts($networkLong, $prefix, $gateway),
        'prefix' => $prefix,
    );
}

function atomfleetproxmoxadmin_parse_prefix(string $mask): int
{
    if (ctype_digit($mask)) {
        return (int) $mask;
    }

    if (!filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new ModuleException('Invalid IPv4 prefix or netmask: ' . $mask);
    }

    $maskLong = ip2long($mask);

    if ($maskLong === false) {
        throw new ModuleException('Invalid IPv4 netmask: ' . $mask);
    }

    $maskUnsigned = sprintf('%u', $maskLong);
    $binary = str_pad(decbin((int) $maskUnsigned), 32, '0', STR_PAD_LEFT);

    if (!preg_match('/^1*0*$/', $binary)) {
        throw new ModuleException('IPv4 netmask must be contiguous: ' . $mask);
    }

    return substr_count($binary, '1');
}

function atomfleetproxmoxadmin_network_long(string $address, int $prefix)
{
    $addressLong = ip2long($address);

    if ($addressLong === false) {
        return false;
    }

    $addressUnsigned = (int) sprintf('%u', $addressLong);
    $hostBits = 32 - $prefix;
    $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << $hostBits) & 0xFFFFFFFF);

    return $addressUnsigned & $mask;
}

function atomfleetproxmoxadmin_expand_network_hosts(int $networkLong, int $prefix, string $gateway): array
{
    $hostCount = 1 << max(0, 32 - $prefix);

    if ($prefix < 20 && $hostCount > 4096) {
        throw new ModuleException('CIDR import is limited to 4096 addresses at a time. Split larger networks into smaller ranges.');
    }

    $start = $networkLong;
    $end = $networkLong + $hostCount - 1;

    if ($prefix <= 30) {
        $start++;
        $end--;
    }

    $gatewayLong = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($gateway) : false;
    $gatewayUnsigned = $gatewayLong === false ? null : (int) sprintf('%u', $gatewayLong);
    $addresses = array();

    for ($current = $start; $current <= $end; $current++) {
        if ($gatewayUnsigned !== null && $current === $gatewayUnsigned) {
            continue;
        }

        $addresses[] = long2ip($current);
    }

    return $addresses;
}

function atomfleetproxmoxadmin_card(string $label, string $value, string $subtext): string
{
    return '<div class="afpa-card">'
        . '<span class="afpa-card-label">' . atomfleetproxmoxadmin_html($label) . '</span>'
        . '<strong class="afpa-card-value">' . atomfleetproxmoxadmin_html($value) . '</strong>'
        . '<span class="afpa-card-subtext">' . atomfleetproxmoxadmin_html($subtext) . '</span>'
        . '</div>';
}

function atomfleetproxmoxadmin_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function atomfleetproxmoxadmin_styles(): string
{
    return <<<HTML
<style>
.afpa-shell{display:block;margin-top:16px}
.afpa-head{align-items:flex-start;display:flex;justify-content:space-between;gap:16px;margin-bottom:16px}
.afpa-head h2{margin:0 0 6px}
.afpa-head p{color:#666;margin:0}
.afpa-hint{background:#f5f7fa;border:1px solid #d9e2ec;border-radius:8px;max-width:360px;padding:12px 14px}
.afpa-toolbar{align-items:center;display:flex;gap:12px;margin-bottom:18px}
.afpa-toolbar label{font-weight:600;margin:0}
.afpa-toolbar select{min-width:320px}
.afpa-cards{display:grid;gap:14px;grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px}
.afpa-card{background:#fff;border:1px solid #dfe7ef;border-radius:10px;display:flex;flex-direction:column;gap:6px;padding:16px}
.afpa-card-label{color:#66788a;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.afpa-card-value{font-size:28px;line-height:1}
.afpa-card-subtext{color:#66788a;font-size:13px}
.afpa-actions{margin-bottom:18px}
.afpa-grid{display:grid;gap:18px;grid-template-columns:minmax(280px,.75fr) minmax(0,1.25fr)}
.afpa-panel{background:#fff;border:1px solid #dfe7ef;border-radius:10px;padding:16px}
.afpa-panel h3{margin:0 0 14px}
.afpa-panel-head-row{align-items:flex-start;display:flex;justify-content:space-between;gap:16px;margin-bottom:16px}
.afpa-panel-head-row p{color:#66788a;margin:6px 0 0}
.afpa-pool-summary{background:#f8fafc;border:1px solid #dfe7ef;border-radius:999px;font-size:12px;font-weight:600;padding:8px 12px}
.afpa-import-form{border:1px solid #ecf1f6;border-radius:10px;margin-bottom:16px;padding:16px}
.afpa-form-grid{display:grid;gap:12px;grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:12px}
.afpa-form-grid label,.afpa-textarea-label{display:block;font-weight:600}
.afpa-form-grid input,.afpa-import-form textarea{margin-top:6px;width:100%}
.afpa-textarea-label{margin-bottom:6px}
.afpa-form-note{color:#66788a;font-size:12px;margin:10px 0 14px}
.afpa-scroll{max-height:640px;overflow:auto}
.afpa-table{width:100%}
.afpa-table th{background:#f8fafc;color:#4c5d70;font-size:12px;letter-spacing:.04em;text-transform:uppercase}
.afpa-table th,.afpa-table td{border-bottom:1px solid #ecf1f6;padding:10px 12px;vertical-align:top}
.afpa-row-actions form{display:inline-block;margin-right:8px}
@media (max-width: 1200px){.afpa-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.afpa-grid,.afpa-form-grid{grid-template-columns:1fr}}
@media (max-width: 720px){.afpa-head,.afpa-toolbar,.afpa-panel-head-row{display:block}.afpa-toolbar select{margin:8px 0;min-width:0;width:100%}.afpa-cards{grid-template-columns:1fr}}
</style>
HTML;
}

function atomfleetproxmoxadmin_flash_set(string $message): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['atomfleetproxmoxadmin_flash'] = $message;
    }
}

function atomfleetproxmoxadmin_flash_consume(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['atomfleetproxmoxadmin_flash'])) {
        return '';
    }

    $message = (string) $_SESSION['atomfleetproxmoxadmin_flash'];
    unset($_SESSION['atomfleetproxmoxadmin_flash']);

    return $message;
}

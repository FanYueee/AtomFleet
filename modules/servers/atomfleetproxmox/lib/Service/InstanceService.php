<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Service;

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;

final class InstanceService extends AbstractProxmoxService
{
    public function testConnection(): void
    {
        $version = $this->client->get('/version');
        $nodes = $this->client->get('/nodes');

        if (empty($version['version'])) {
            throw new ModuleException('Proxmox API version response was empty.');
        }

        if (!is_array($nodes) || empty($nodes)) {
            throw new ModuleException('No Proxmox nodes were returned for this connection.');
        }
    }

    public function getDashboardPayload(): array
    {
        $descriptor = $this->resolveDescriptor(false);

        if ($descriptor === null) {
            return $this->buildUnlinkedPayload();
        }

        try {
            $status = $this->client->get($this->guestBasePath($descriptor) . '/status/current');
            $config = $this->client->get($this->guestBasePath($descriptor) . '/config');
        } catch (\Throwable $e) {
            return $this->buildBrokenLinkPayload($descriptor, $e->getMessage());
        }

        return array(
            'meta' => array(
                'provisioned' => true,
                'updatedAt' => gmdate('c'),
                'panelUrl' => $this->panelUrl(),
                'warning' => '',
            ),
            'instance' => array(
                'vmid' => $descriptor['vmid'],
                'node' => $descriptor['node'],
                'guestType' => $descriptor['guestType'],
                'guestTypeLabel' => strtoupper($descriptor['guestType']),
                'name' => $descriptor['name'] !== '' ? $descriptor['name'] : $this->params->instanceName(),
            ),
            'spec' => $this->buildSpecSummary($config, $descriptor),
            'stats' => $this->buildStatsSummary($status),
            'actions' => $this->buildActions((string) ($status['status'] ?? 'stopped')),
        );
    }

    public function powerAction(string $action): array
    {
        $this->performPowerAction($action);

        return array(
            'message' => 'Power action "' . $action . '" completed.',
            'dashboard' => $this->getDashboardPayload(),
        );
    }

    public function performPowerAction(string $action): void
    {
        $validActions = array('start', 'shutdown', 'reboot', 'stop');

        if (!in_array($action, $validActions, true)) {
            throw new ModuleException('Unsupported power action: ' . $action);
        }

        $descriptor = $this->resolveDescriptor();
        $task = $this->client->post($this->guestBasePath($descriptor) . '/status/' . $action);
        $this->waitForTask($descriptor['node'], $task);
    }

    public function refreshLink(): array
    {
        $descriptor = $this->resolveDescriptor();
        $this->persistDescriptor($descriptor);

        return $descriptor;
    }

    public function unlinkGuest(): void
    {
        $this->clearDescriptor();
    }

    public function linkExistingGuest(int $vmid, string $node = '', string $guestType = '', string $name = ''): array
    {
        if ($vmid <= 0) {
            throw new ModuleException('VMID must be a positive integer.');
        }

        $guestType = $this->normalizeGuestType($guestType);
        $descriptor = array(
            'vmid' => $vmid,
            'node' => trim($node),
            'guestType' => $guestType,
            'name' => trim($name),
        );
        $remote = $this->locateGuestByVmid($vmid);

        if ($remote !== null) {
            $descriptor = array_merge($descriptor, $remote);
        }

        if ($descriptor['node'] === '') {
            throw new ModuleException('Node is required when the remote guest cannot be auto-discovered.');
        }

        if (!in_array($descriptor['guestType'], array('qemu', 'lxc'), true)) {
            throw new ModuleException('Guest type must be "qemu" or "lxc".');
        }

        if ($descriptor['name'] === '') {
            $descriptor['name'] = 'vm-' . $vmid;
        }

        $this->persistDescriptor($descriptor);

        return $descriptor;
    }

    public function getPanelUrl(): string
    {
        return $this->panelUrl();
    }

    private function buildUnlinkedPayload(): array
    {
        $guestType = $this->params->guestType();
        $cpuCores = max(1, $this->params->intConfig('CPU Cores', 2));
        $memoryMb = max(256, $this->params->intConfig('Memory (MB)', 2048));
        $diskGb = max(1, $this->params->intConfig('Disk Size (GB)', 20));

        return array(
            'meta' => array(
                'provisioned' => false,
                'updatedAt' => gmdate('c'),
                'panelUrl' => $this->panelUrl(),
                'warning' => 'This service is not linked to a Proxmox guest yet.',
            ),
            'instance' => array(
                'vmid' => null,
                'node' => trim((string) $this->params->config('Node', 'auto')),
                'guestType' => $guestType,
                'guestTypeLabel' => strtoupper($guestType),
                'name' => $this->params->instanceName(),
            ),
            'spec' => array(
                'cpuCores' => $cpuCores,
                'memoryMb' => $memoryMb,
                'diskGb' => $diskGb,
                'bridge' => trim((string) $this->params->config('Network Bridge', 'vmbr0')),
                'primaryIpv4' => trim((string) $this->params->serviceProperty('Dedicated IP', '')),
                'systemLabel' => $this->configuredSystemLabel($guestType),
            ),
            'stats' => array(
                'status' => 'not_provisioned',
                'statusLabel' => 'Not Provisioned',
                'uptimeSeconds' => 0,
                'uptimeLabel' => 'Not provisioned yet',
                'cpu' => array('percent' => 0, 'display' => '0%'),
                'memory' => array('percent' => 0, 'usedBytes' => 0, 'totalBytes' => (int) ($memoryMb * 1024 * 1024), 'display' => '0%', 'subtitle' => '0 B / ' . $this->formatBytes((int) ($memoryMb * 1024 * 1024))),
                'disk' => array('percent' => 0, 'usedBytes' => 0, 'totalBytes' => (int) ($diskGb * 1024 * 1024 * 1024), 'display' => '0%', 'subtitle' => '0 B / ' . $this->formatBytes((int) ($diskGb * 1024 * 1024 * 1024))),
                'network' => array('inBytes' => 0, 'outBytes' => 0, 'readBytes' => 0, 'writeBytes' => 0),
            ),
            'actions' => $this->buildActions('not_provisioned'),
        );
    }

    private function buildBrokenLinkPayload(array $descriptor, string $warning): array
    {
        return array(
            'meta' => array(
                'provisioned' => true,
                'updatedAt' => gmdate('c'),
                'panelUrl' => $this->panelUrl(),
                'warning' => $warning,
            ),
            'instance' => array(
                'vmid' => $descriptor['vmid'],
                'node' => $descriptor['node'],
                'guestType' => $descriptor['guestType'],
                'guestTypeLabel' => strtoupper($descriptor['guestType']),
                'name' => $descriptor['name'] !== '' ? $descriptor['name'] : ('vm-' . $descriptor['vmid']),
            ),
            'spec' => array(
                'cpuCores' => $this->params->intConfig('CPU Cores', 2),
                'memoryMb' => $this->params->intConfig('Memory (MB)', 2048),
                'diskGb' => $this->params->intConfig('Disk Size (GB)', 20),
                'bridge' => trim((string) $this->params->config('Network Bridge', 'vmbr0')),
                'primaryIpv4' => trim((string) $this->params->serviceProperty('Dedicated IP', '')),
                'systemLabel' => $this->configuredSystemLabel($descriptor['guestType']),
            ),
            'stats' => array(
                'status' => 'unavailable',
                'statusLabel' => 'Unavailable',
                'uptimeSeconds' => 0,
                'uptimeLabel' => 'Unavailable',
                'cpu' => array('percent' => 0, 'display' => '--'),
                'memory' => array('percent' => 0, 'usedBytes' => 0, 'totalBytes' => 0, 'display' => '--', 'subtitle' => ''),
                'disk' => array('percent' => 0, 'usedBytes' => 0, 'totalBytes' => 0, 'display' => '--', 'subtitle' => ''),
                'network' => array('inBytes' => 0, 'outBytes' => 0, 'readBytes' => 0, 'writeBytes' => 0),
            ),
            'actions' => $this->buildActions('unavailable'),
        );
    }

    private function buildStatsSummary(array $status): array
    {
        $cpuPercent = $this->percent($status['cpu'] ?? 0.0);
        $memoryUsed = (int) ($status['mem'] ?? 0);
        $memoryTotal = (int) ($status['maxmem'] ?? 0);
        $diskUsed = (int) ($status['disk'] ?? 0);
        $diskTotal = (int) ($status['maxdisk'] ?? 0);
        $memoryPercent = $memoryTotal > 0 ? round(($memoryUsed / $memoryTotal) * 100, 1) : 0.0;
        $diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0.0;
        $statusLabel = $this->statusLabel((string) ($status['status'] ?? 'unknown'));
        $uptimeSeconds = (int) ($status['uptime'] ?? 0);

        return array(
            'status' => strtolower((string) ($status['status'] ?? 'unknown')),
            'statusLabel' => $statusLabel,
            'uptimeSeconds' => $uptimeSeconds,
            'uptimeLabel' => $this->formatDuration($uptimeSeconds),
            'cpu' => array(
                'percent' => $cpuPercent,
                'display' => number_format($cpuPercent, 1) . '%',
            ),
            'memory' => array(
                'percent' => $memoryPercent,
                'usedBytes' => $memoryUsed,
                'totalBytes' => $memoryTotal,
                'display' => number_format($memoryPercent, 1) . '%',
                'subtitle' => $this->formatBytes($memoryUsed) . ' / ' . $this->formatBytes($memoryTotal),
            ),
            'disk' => array(
                'percent' => $diskPercent,
                'usedBytes' => $diskUsed,
                'totalBytes' => $diskTotal,
                'display' => number_format($diskPercent, 1) . '%',
                'subtitle' => $this->formatBytes($diskUsed) . ' / ' . $this->formatBytes($diskTotal),
            ),
            'network' => array(
                'inBytes' => (int) ($status['netin'] ?? 0),
                'outBytes' => (int) ($status['netout'] ?? 0),
                'readBytes' => (int) ($status['diskread'] ?? 0),
                'writeBytes' => (int) ($status['diskwrite'] ?? 0),
            ),
        );
    }

    private function buildSpecSummary(array $config, array $descriptor): array
    {
        $cpuCores = (int) ($config['cores'] ?? $this->params->intConfig('CPU Cores', 2));
        $memoryMb = (int) ($config['memory'] ?? $this->params->intConfig('Memory (MB)', 2048));
        $bridge = $this->extractBridge((string) ($config['net0'] ?? ''));

        return array(
            'cpuCores' => $cpuCores,
            'memoryMb' => $memoryMb,
            'diskGb' => $this->params->intConfig('Disk Size (GB)', 20),
            'bridge' => $bridge !== '' ? $bridge : trim((string) $this->params->config('Network Bridge', 'vmbr0')),
            'primaryIpv4' => trim((string) $this->params->serviceProperty('Dedicated IP', '')),
            'systemLabel' => $this->configuredSystemLabel($descriptor['guestType'], $config),
            'onBoot' => !empty($config['onboot']),
            'nameserver' => trim((string) ($config['nameserver'] ?? $this->params->config('Nameserver', ''))),
            'searchDomain' => trim((string) ($config['searchdomain'] ?? $this->params->config('Search Domain', ''))),
        );
    }

    private function configuredSystemLabel(string $guestType, array $config = array()): string
    {
        if ($guestType === 'qemu') {
            $templateVmid = trim((string) $this->params->config('QEMU Template VMID', ''));

            if ($templateVmid !== '' && ctype_digit($templateVmid)) {
                $template = $this->locateGuestByVmid((int) $templateVmid);

                if ($template !== null && !empty($template['name'])) {
                    return (string) $template['name'];
                }

                return 'Template #' . $templateVmid;
            }

            $ostype = trim((string) ($config['ostype'] ?? ''));

            if ($ostype !== '') {
                return strtoupper($ostype);
            }

            return '--';
        }

        $template = trim((string) $this->params->config('LXC Template', ''));

        if ($template === '') {
            return '--';
        }

        $path = strpos($template, ':') !== false ? substr($template, strpos($template, ':') + 1) : $template;
        $name = basename($path);
        $name = preg_replace('/\.(tar\.)?(gz|xz|zst|bz2)$/i', '', $name);
        $name = str_replace(array('_', '.'), ' ', $name);

        return trim((string) $name) !== '' ? trim((string) $name) : $template;
    }

    private function buildActions(string $status): array
    {
        $running = $status === 'running';
        $provisioned = !in_array($status, array('not_provisioned', 'unavailable'), true);

        return array(
            array('key' => 'start', 'label' => 'Start', 'enabled' => $provisioned && !$running),
            array('key' => 'shutdown', 'label' => 'Shutdown', 'enabled' => $provisioned && $running),
            array('key' => 'reboot', 'label' => 'Reboot', 'enabled' => $provisioned && $running),
            array('key' => 'stop', 'label' => 'Force Stop', 'enabled' => $provisioned && $running),
        );
    }

    private function extractBridge(string $netDefinition): string
    {
        if ($netDefinition === '') {
            return '';
        }

        $segments = explode(',', $netDefinition);

        foreach ($segments as $segment) {
            $parts = explode('=', $segment, 2);

            if (count($parts) === 2 && trim($parts[0]) === 'bridge') {
                return trim($parts[1]);
            }
        }

        return '';
    }
}

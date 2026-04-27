<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Service;

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;

final class ProvisioningService extends AbstractProxmoxService
{
    public function createAccount(): void
    {
        if ($this->resolveDescriptor(false) !== null) {
            throw new ModuleException('This WHMCS service is already linked to a Proxmox guest.');
        }

        $guestType = $this->params->guestType();
        $ipAllocation = new IpAllocationService($this->params);
        $allocation = array(
            'source' => 'dhcp',
            'newlyAllocated' => false,
        );

        try {
            $allocation = $ipAllocation->ensureAssignedIpv4();

            if ($guestType === 'qemu') {
                $descriptor = $this->createQemu();
            } elseif ($guestType === 'lxc') {
                $descriptor = $this->createLxc();
            } else {
                throw new ModuleException('Unsupported guest type: ' . $guestType);
            }

            $this->persistDescriptor($descriptor);

            if ($this->params->boolConfig('Start After Provision')) {
                $task = $this->client->post($this->guestBasePath($descriptor) . '/status/start');
                $this->waitForTask($descriptor['node'], $task);
            }
        } catch (\Throwable $e) {
            if (($allocation['source'] ?? '') === 'pool' && !empty($allocation['newlyAllocated'])) {
                $ipAllocation->releaseAssignedIpv4(true);
            }

            throw $e;
        }
    }

    public function suspendAccount(): void
    {
        $descriptor = $this->resolveDescriptor();
        $task = $this->client->post($this->guestBasePath($descriptor) . '/status/stop');
        $this->waitForTask($descriptor['node'], $task);
    }

    public function unsuspendAccount(): void
    {
        $descriptor = $this->resolveDescriptor();
        $task = $this->client->post($this->guestBasePath($descriptor) . '/status/start');
        $this->waitForTask($descriptor['node'], $task);
    }

    public function terminateAccount(): void
    {
        $descriptor = $this->resolveDescriptor();
        $current = $this->client->get($this->guestBasePath($descriptor) . '/status/current');

        if (($current['status'] ?? '') === 'running') {
            $stopTask = $this->client->post($this->guestBasePath($descriptor) . '/status/stop');
            $this->waitForTask($descriptor['node'], $stopTask);
        }

        $deletePayload = $descriptor['guestType'] === 'qemu'
            ? array('purge' => 1, 'destroy-unreferenced-disks' => 1)
            : array('purge' => 1);

        $task = $this->client->delete($this->guestBasePath($descriptor), $deletePayload);
        $this->waitForTask($descriptor['node'], $task);

        if ($this->params->boolConfig('Release IP On Terminate', true)) {
            (new IpAllocationService($this->params))->releaseAssignedIpv4(true);
        }

        $this->clearDescriptor();
    }

    public function changePackage(): void
    {
        $descriptor = $this->resolveDescriptor();

        if ($descriptor['guestType'] === 'qemu') {
            $payload = $this->buildQemuConfigPayload();
            $this->client->post($this->guestBasePath($descriptor) . '/config', $payload);

            return;
        }

        $payload = $this->buildLxcConfigPayload();
        $this->client->put($this->guestBasePath($descriptor) . '/config', $payload);
    }

    public function changePassword(): void
    {
        $descriptor = $this->resolveDescriptor();
        $password = (string) $this->params->get('password', '');

        if ($password === '') {
            throw new ModuleException('A password value is required.');
        }

        if ($descriptor['guestType'] === 'qemu') {
            $this->client->post($this->guestBasePath($descriptor) . '/config', array('cipassword' => $password));

            return;
        }

        $this->client->put($this->guestBasePath($descriptor) . '/config', array('password' => $password));
    }

    private function createQemu(): array
    {
        $templateVmid = $this->params->intConfig('QEMU Template VMID');

        if ($templateVmid <= 0) {
            throw new ModuleException('QEMU Template VMID is required for QEMU provisioning.');
        }

        $targetNode = $this->resolveTargetNode('qemu');
        $templateNode = $this->resolveTemplateNode($templateVmid, $targetNode);
        $descriptor = array(
            'vmid' => $this->nextVmid(),
            'node' => $targetNode,
            'guestType' => 'qemu',
            'name' => $this->params->instanceName(),
        );
        $clonePayload = array(
            'newid' => $descriptor['vmid'],
            'name' => $descriptor['name'],
        );
        $guestCreated = false;

        if ($this->params->config('Clone Mode', 'full') !== 'linked') {
            $clonePayload['full'] = 1;
        }

        if ($templateNode !== $targetNode) {
            $clonePayload['target'] = $targetNode;
        }

        $pool = trim((string) $this->params->config('Proxmox Pool', ''));

        if ($pool !== '') {
            $clonePayload['pool'] = $pool;
        }

        try {
            $task = $this->client->post(
                sprintf('/nodes/%s/qemu/%d/clone', rawurlencode($templateNode), $templateVmid),
                $clonePayload
            );

            $this->waitForTask($templateNode, $task);
            $guestCreated = true;
            $this->client->post($this->guestBasePath($descriptor) . '/config', $this->buildQemuConfigPayload());
            $this->resizeQemuDiskIfNeeded($descriptor);
            $this->saveIpPropertyIfPresent();

            return $descriptor;
        } catch (\Throwable $e) {
            if ($guestCreated) {
                $this->cleanupFailedProvision($descriptor);
            }

            throw $e;
        }
    }

    private function createLxc(): array
    {
        $template = trim((string) $this->params->config('LXC Template', ''));

        if ($template === '') {
            throw new ModuleException('LXC Template is required for LXC provisioning.');
        }

        $descriptor = array(
            'vmid' => $this->nextVmid(),
            'node' => $this->resolveTargetNode('lxc'),
            'guestType' => 'lxc',
            'name' => $this->params->instanceName(),
        );
        $storage = trim((string) $this->params->config('Storage', 'local-lvm'));
        $diskGb = max(1, $this->params->intConfig('Disk Size (GB)', 20));
        $password = (string) $this->params->get('password', '');

        if ($password === '') {
            throw new ModuleException('A service password is required for LXC provisioning.');
        }

        $payload = array(
            'vmid' => $descriptor['vmid'],
            'hostname' => $descriptor['name'],
            'ostemplate' => $template,
            'rootfs' => $storage . ':' . $diskGb,
            'cores' => max(1, $this->params->intConfig('CPU Cores', 2)),
            'memory' => max(256, $this->params->intConfig('Memory (MB)', 2048)),
            'password' => $password,
            'onboot' => $this->params->boolConfig('Start On Boot') ? 1 : 0,
        );
        $guestCreated = false;

        $bridge = trim((string) $this->params->config('Network Bridge', 'vmbr0'));
        $network = $this->buildLxcNetDefinition($bridge);

        if ($network !== '') {
            $payload['net0'] = $network;
        }

        $nameserver = trim((string) $this->params->config('Nameserver', ''));
        $searchDomain = trim((string) $this->params->config('Search Domain', ''));
        $pool = trim((string) $this->params->config('Proxmox Pool', ''));

        if ($nameserver !== '') {
            $payload['nameserver'] = $nameserver;
        }

        if ($searchDomain !== '') {
            $payload['searchdomain'] = $searchDomain;
        }

        if ($pool !== '') {
            $payload['pool'] = $pool;
        }

        try {
            $task = $this->client->post(
                sprintf('/nodes/%s/lxc', rawurlencode($descriptor['node'])),
                $payload
            );

            $this->waitForTask($descriptor['node'], $task);
            $guestCreated = true;
            $this->saveIpPropertyIfPresent();

            return $descriptor;
        } catch (\Throwable $e) {
            if ($guestCreated) {
                $this->cleanupFailedProvision($descriptor);
            }

            throw $e;
        }
    }

    private function resolveTargetNode(string $guestType): string
    {
        $configured = trim((string) $this->params->config('Node', 'auto'));

        if ($configured !== '' && strtolower($configured) !== 'auto') {
            return $configured;
        }

        if ($guestType === 'qemu') {
            $templateVmid = $this->params->intConfig('QEMU Template VMID');

            if ($templateVmid > 0) {
                $template = $this->locateGuestByVmid($templateVmid);

                if ($template !== null && !empty($template['node'])) {
                    return $template['node'];
                }
            }
        }

        return $this->pickFirstOnlineNode();
    }

    private function resolveTemplateNode(int $templateVmid, string $targetNode): string
    {
        $configuredTemplateNode = trim((string) $this->params->config('Template Node', ''));

        if ($configuredTemplateNode !== '') {
            return $configuredTemplateNode;
        }

        $template = $this->locateGuestByVmid($templateVmid);

        if ($template !== null && !empty($template['node'])) {
            return $template['node'];
        }

        return $targetNode;
    }

    private function buildQemuConfigPayload(): array
    {
        $payload = array(
            'cores' => max(1, $this->params->intConfig('CPU Cores', 2)),
            'memory' => max(256, $this->params->intConfig('Memory (MB)', 2048)),
            'onboot' => $this->params->boolConfig('Start On Boot') ? 1 : 0,
        );
        $bridge = trim((string) $this->params->config('Network Bridge', 'vmbr0'));
        $nicModel = trim((string) $this->params->config('NIC Model', 'virtio'));
        $nameserver = trim((string) $this->params->config('Nameserver', ''));
        $searchDomain = trim((string) $this->params->config('Search Domain', ''));
        $pool = trim((string) $this->params->config('Proxmox Pool', ''));
        $ciUser = trim((string) $this->params->config('Cloud-Init User', 'root'));
        $password = (string) $this->params->get('password', '');
        $ipConfig = $this->buildQemuIpConfig();

        if ($bridge !== '') {
            $payload['net0'] = $this->buildQemuNetDefinition($nicModel, $bridge);
        }

        if ($ipConfig !== '') {
            $payload['ipconfig0'] = $ipConfig;
        }

        if ($nameserver !== '') {
            $payload['nameserver'] = $nameserver;
        }

        if ($searchDomain !== '') {
            $payload['searchdomain'] = $searchDomain;
        }

        if ($pool !== '') {
            $payload['pool'] = $pool;
        }

        if ($ciUser !== '') {
            $payload['ciuser'] = $ciUser;
        }

        if ($password !== '') {
            $payload['cipassword'] = $password;
        }

        $sshKey = trim((string) $this->params->customField('SSH Key', ''));

        if ($sshKey !== '') {
            $payload['sshkeys'] = $sshKey;
        }

        return $payload;
    }

    private function buildQemuNetDefinition(string $nicModel, string $bridge): string
    {
        $model = strtolower(trim($nicModel));

        if ($model === '') {
            $model = 'virtio';
        }

        return 'model=' . $model . ',bridge=' . $bridge;
    }

    private function buildLxcConfigPayload(): array
    {
        $payload = array(
            'cores' => max(1, $this->params->intConfig('CPU Cores', 2)),
            'memory' => max(256, $this->params->intConfig('Memory (MB)', 2048)),
            'onboot' => $this->params->boolConfig('Start On Boot') ? 1 : 0,
        );
        $bridge = trim((string) $this->params->config('Network Bridge', 'vmbr0'));
        $nameserver = trim((string) $this->params->config('Nameserver', ''));
        $searchDomain = trim((string) $this->params->config('Search Domain', ''));
        $network = $this->buildLxcNetDefinition($bridge);

        if ($network !== '') {
            $payload['net0'] = $network;
        }

        if ($nameserver !== '') {
            $payload['nameserver'] = $nameserver;
        }

        if ($searchDomain !== '') {
            $payload['searchdomain'] = $searchDomain;
        }

        return $payload;
    }

    private function buildQemuIpConfig(): string
    {
        $segments = array();
        $ipv4 = $this->staticIpv4Address();
        $ipv4Prefix = $this->staticIpv4Prefix();
        $ipv4Gateway = $this->staticIpv4Gateway();
        $ipv6 = $this->staticIpv6Address();
        $ipv6Prefix = $this->staticIpv6Prefix();
        $ipv6Gateway = $this->staticIpv6Gateway();

        if ($ipv4 !== '' && $ipv4Prefix !== '') {
            $segments[] = 'ip=' . $ipv4 . '/' . $ipv4Prefix;

            if ($ipv4Gateway !== '') {
                $segments[] = 'gw=' . $ipv4Gateway;
            }
        } else {
            $segments[] = 'ip=dhcp';
        }

        if ($ipv6 !== '' && $ipv6Prefix !== '') {
            $segments[] = 'ip6=' . $ipv6 . '/' . $ipv6Prefix;

            if ($ipv6Gateway !== '') {
                $segments[] = 'gw6=' . $ipv6Gateway;
            }
        }

        return implode(',', $segments);
    }

    private function buildLxcNetDefinition(string $bridge): string
    {
        if ($bridge === '') {
            return '';
        }

        $segments = array(
            'name=eth0',
            'bridge=' . $bridge,
        );
        $ipv4 = $this->staticIpv4Address();
        $ipv4Prefix = $this->staticIpv4Prefix();
        $ipv4Gateway = $this->staticIpv4Gateway();
        $ipv6 = $this->staticIpv6Address();
        $ipv6Prefix = $this->staticIpv6Prefix();
        $ipv6Gateway = $this->staticIpv6Gateway();

        if ($ipv4 !== '' && $ipv4Prefix !== '') {
            $segments[] = 'ip=' . $ipv4 . '/' . $ipv4Prefix;

            if ($ipv4Gateway !== '') {
                $segments[] = 'gw=' . $ipv4Gateway;
            }
        } else {
            $segments[] = 'ip=dhcp';
        }

        if ($ipv6 !== '' && $ipv6Prefix !== '') {
            $segments[] = 'ip6=' . $ipv6 . '/' . $ipv6Prefix;

            if ($ipv6Gateway !== '') {
                $segments[] = 'gw6=' . $ipv6Gateway;
            }
        } else {
            $segments[] = 'ip6=auto';
        }

        return implode(',', $segments);
    }

    private function resizeQemuDiskIfNeeded(array $descriptor): void
    {
        $desiredDiskGb = $this->params->intConfig('Disk Size (GB)', 0);

        if ($desiredDiskGb <= 0) {
            return;
        }

        $config = $this->client->get($this->guestBasePath($descriptor) . '/config');
        $diskKey = '';
        $currentDiskGb = 0;

        foreach ($config as $key => $value) {
            if (!preg_match('/^(scsi|virtio|sata|ide)\d+$/', (string) $key)) {
                continue;
            }

            if (!is_string($value) || strpos($value, ':') === false) {
                continue;
            }

            if (stripos($value, 'media=cdrom') !== false || stripos($value, 'cloudinit') !== false) {
                continue;
            }

            $diskKey = (string) $key;

            if (preg_match('/size=(\d+(?:\.\d+)?)G/i', $value, $matches)) {
                $currentDiskGb = (int) ceil((float) $matches[1]);
            }

            break;
        }

        if ($diskKey === '' || $desiredDiskGb <= $currentDiskGb) {
            return;
        }

        $task = $this->client->put(
            $this->guestBasePath($descriptor) . '/resize',
            array(
                'disk' => $diskKey,
                'size' => $desiredDiskGb . 'G',
            )
        );

        $this->waitForTask($descriptor['node'], $task);
    }

    private function cleanupFailedProvision(array $descriptor): void
    {
        try {
            $current = $this->client->get($this->guestBasePath($descriptor) . '/status/current');

            if (($current['status'] ?? '') === 'running') {
                $stopTask = $this->client->post($this->guestBasePath($descriptor) . '/status/stop');
                $this->waitForTask($descriptor['node'], $stopTask);
            }

            $deletePayload = $descriptor['guestType'] === 'qemu'
                ? array('purge' => 1, 'destroy-unreferenced-disks' => 1)
                : array('purge' => 1);

            $task = $this->client->delete($this->guestBasePath($descriptor), $deletePayload);
            $this->waitForTask($descriptor['node'], $task);
        } catch (\Throwable $e) {
        }
    }

    private function saveIpPropertyIfPresent(): void
    {
        $ipv4 = $this->staticIpv4Address();

        if ($ipv4 === '') {
            return;
        }

        $this->params->saveServiceProperties(array('Dedicated IP' => $ipv4));
    }

    private function staticIpv4Address(): string
    {
        $value = trim((string) $this->params->customField('IPv4 Address', ''));

        if ($value === '') {
            $value = trim((string) $this->params->serviceProperty('Allocated IPv4', ''));
        }

        if ($value === '') {
            $value = trim((string) $this->params->serviceProperty('Dedicated IP', ''));
        }

        if ($value === '') {
            $value = trim((string) $this->params->get('dedicatedip', ''));
        }

        return $value;
    }

    private function staticIpv4Prefix(): string
    {
        $value = trim((string) $this->params->customField('IPv4 Prefix', ''));

        if ($value === '') {
            $value = trim((string) $this->params->customField('IPv4 CIDR', ''));
        }

        if ($value === '') {
            $value = trim((string) $this->params->serviceProperty('Allocated IPv4 Prefix', ''));
        }

        return $value;
    }

    private function staticIpv4Gateway(): string
    {
        $value = trim((string) $this->params->customField('IPv4 Gateway', ''));

        if ($value === '') {
            $value = trim((string) $this->params->serviceProperty('Allocated IPv4 Gateway', ''));
        }

        return $value;
    }

    private function staticIpv6Address(): string
    {
        return trim((string) $this->params->customField('IPv6 Address', ''));
    }

    private function staticIpv6Prefix(): string
    {
        return trim((string) $this->params->customField('IPv6 Prefix', ''));
    }

    private function staticIpv6Gateway(): string
    {
        return trim((string) $this->params->customField('IPv6 Gateway', ''));
    }
}

<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Service;

use AtomFleet\Whmcs\Proxmox\Api\ProxmoxApiClient;
use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;
use AtomFleet\Whmcs\Proxmox\Support\ModuleParameters;

abstract class AbstractProxmoxService
{
    /** @var ModuleParameters */
    protected $params;

    /** @var ProxmoxApiClient */
    protected $client;

    public function __construct(array $params)
    {
        $this->params = new ModuleParameters($params);
        $this->client = new ProxmoxApiClient($this->params);
    }

    protected function resolveDescriptor(bool $required = true)
    {
        $vmid = trim((string) $this->params->serviceProperty('VMID', ''));

        if ($vmid === '') {
            $vmid = trim((string) $this->params->customField('VMID', ''));
        }

        if ($vmid === '') {
            $username = trim((string) $this->params->get('username', ''));

            if ($username !== '' && ctype_digit($username)) {
                $vmid = $username;
            }
        }

        if ($vmid === '') {
            if ($required) {
                throw new ModuleException('This WHMCS service is not linked to a Proxmox guest yet.');
            }

            return null;
        }

        $descriptor = array(
            'vmid' => (int) $vmid,
            'node' => trim((string) $this->params->serviceProperty('Node', '')),
            'guestType' => $this->normalizeGuestType((string) $this->params->serviceProperty('Guest Type', $this->params->guestType())),
            'name' => trim((string) $this->params->serviceProperty('Instance Name', '')),
        );

        $remote = $this->locateGuestByVmid($descriptor['vmid']);

        if ($remote !== null) {
            $descriptor = array_merge($descriptor, $remote);
            $this->persistDescriptor($descriptor);
        }

        if ($descriptor['node'] === '' || !in_array($descriptor['guestType'], array('qemu', 'lxc'), true)) {
            if ($required) {
                throw new ModuleException('Unable to resolve the linked Proxmox guest. Check VMID/Node mapping.');
            }

            return null;
        }

        return $descriptor;
    }

    protected function persistDescriptor(array $descriptor): void
    {
        $this->params->saveServiceProperties(array(
            'VMID' => $descriptor['vmid'],
            'Node' => $descriptor['node'],
            'Guest Type' => $descriptor['guestType'],
            'Instance Name' => isset($descriptor['name']) ? $descriptor['name'] : '',
            'Proxmox URI' => $this->panelUrl(),
            'Username' => $descriptor['vmid'],
        ));
    }

    protected function clearDescriptor(): void
    {
        $this->params->saveServiceProperties(array(
            'VMID' => '',
            'Node' => '',
            'Guest Type' => '',
            'Instance Name' => '',
            'Proxmox URI' => '',
        ));
    }

    protected function locateGuestByVmid(int $vmid)
    {
        $resources = $this->getClusterVmResources();

        foreach ($resources as $resource) {
            if ((int) ($resource['vmid'] ?? 0) !== $vmid) {
                continue;
            }

            $guestType = $this->normalizeGuestType((string) ($resource['type'] ?? ''));

            if (!in_array($guestType, array('qemu', 'lxc'), true)) {
                continue;
            }

            return array(
                'vmid' => $vmid,
                'node' => (string) ($resource['node'] ?? ''),
                'guestType' => $guestType,
                'name' => (string) ($resource['name'] ?? ''),
            );
        }

        return null;
    }

    protected function getClusterVmResources(): array
    {
        $resources = $this->client->get('/cluster/resources', array('type' => 'vm'));

        if (!is_array($resources)) {
            return array();
        }

        return array_values(array_filter($resources, function ($resource) {
            $guestType = $this->normalizeGuestType((string) ($resource['type'] ?? ''));

            if (!in_array($guestType, array('qemu', 'lxc'), true)) {
                return false;
            }

            if (!empty($resource['template'])) {
                return false;
            }

            return !empty($resource['vmid']);
        }));
    }

    protected function pickFirstOnlineNode(): string
    {
        $nodes = $this->client->get('/nodes');

        if (!is_array($nodes) || empty($nodes)) {
            throw new ModuleException('No Proxmox nodes were returned by the API.');
        }

        foreach ($nodes as $node) {
            if (($node['status'] ?? '') === 'online' && !empty($node['node'])) {
                return (string) $node['node'];
            }
        }

        if (!empty($nodes[0]['node'])) {
            return (string) $nodes[0]['node'];
        }

        throw new ModuleException('Unable to determine a usable Proxmox node.');
    }

    protected function resolvePreferredNode(string $preferred = ''): string
    {
        $preferred = trim($preferred);

        if ($preferred !== '' && strtolower($preferred) !== 'auto') {
            return $preferred;
        }

        return $this->pickFirstOnlineNode();
    }

    protected function nextVmid(): int
    {
        return (int) $this->client->get('/cluster/nextid');
    }

    protected function guestBasePath(array $descriptor): string
    {
        return sprintf(
            '/nodes/%s/%s/%d',
            rawurlencode((string) $descriptor['node']),
            $descriptor['guestType'],
            (int) $descriptor['vmid']
        );
    }

    protected function waitForTask(string $node, $taskReference, int $timeoutSeconds = 180): void
    {
        if (!is_string($taskReference) || strpos($taskReference, 'UPID:') !== 0) {
            return;
        }

        $deadline = time() + $timeoutSeconds;
        $taskPath = sprintf('/nodes/%s/tasks/%s/status', rawurlencode($node), rawurlencode($taskReference));

        do {
            $status = $this->client->get($taskPath);

            if (($status['status'] ?? '') === 'stopped') {
                if (($status['exitstatus'] ?? '') === 'OK') {
                    return;
                }

                $exitStatus = isset($status['exitstatus']) ? (string) $status['exitstatus'] : 'unknown';
                throw new ModuleException('Proxmox task failed with exit status: ' . $exitStatus);
            }

            usleep(500000);
        } while (time() < $deadline);

        throw new ModuleException('Timed out while waiting for the Proxmox task to finish.');
    }

    protected function normalizeGuestType(string $guestType): string
    {
        $guestType = strtolower(trim($guestType));

        if ($guestType === 'vm') {
            return 'qemu';
        }

        return $guestType;
    }

    protected function statusLabel(string $status): string
    {
        $status = strtolower(trim($status));

        if ($status === '') {
            return 'Unknown';
        }

        if ($status === 'not_provisioned') {
            return 'Not Provisioned';
        }

        return ucfirst($status);
    }

    protected function percent($value): float
    {
        return round(((float) $value) * 100, 1);
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = array();

        if ($days > 0) {
            $parts[] = $days . 'd';
        }

        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        if ($minutes > 0 && count($parts) < 2) {
            $parts[] = $minutes . 'm';
        }

        return implode(' ', $parts);
    }

    protected function panelUrl(): string
    {
        return $this->params->panelBaseUrl() . '/';
    }

    protected function formatBytes(int $bytes): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
        $value = (float) max($bytes, 0);
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
    }
}
